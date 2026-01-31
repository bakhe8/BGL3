<?php

// Resilient vendor discovery
$vendorPath = ($argc >= 5) ? $argv[4] : (getenv('BGL_VENDOR_PATH') ?: (__DIR__ . '/../../vendor'));

if (file_exists($vendorPath . '/autoload.php')) {
    require $vendorPath . '/autoload.php';
} else {
    echo json_encode(['status' => 'error', 'message' => 'Autoload not found at ' . $vendorPath]);
    exit(1);
}

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\PrettyPrinter;
use PhpParser\BuilderFactory;
use PhpParser\Node\Name;
use PhpParser\Parser;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias as TraitAlias;
use PhpParser\Node\Scalar\String_;

function parseMethodBody(string $content, Parser $parser): array
{
    // Wrap content in a temporary function to reuse the existing parser safely
    $wrapper = "<?php\nfunction __temp__(){\n" . $content . "\n}";
    try {
        $stmts = $parser->parse($wrapper);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Function_ && $stmt->name->toString() === '__temp__') {
                return $stmt->stmts ?? [];
            }
        }
    } catch (Error $e) {
        throw new Error("Unable to parse method content: " . $e->getMessage());
    }
    return [];
}

// Usage: php patcher.php <file> <action> <json_params>
if ($argc < 4) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments. Usage: patcher.php <file> <action> <json_params>']);
    exit(1);
}

$filePath = $argv[1];
$action = $argv[2];
$params = json_decode($argv[3], true);

if (!file_exists($filePath)) {
    echo json_encode(['status' => 'error', 'message' => 'File not found']);
    exit(1);
}

$code = file_get_contents($filePath);
$parser = (new ParserFactory)->createForNewestSupportedVersion();

try {
    $stmts = $parser->parse($code);
    $tokens = $parser->getTokens();
} catch (Error $e) {
    echo json_encode(['status' => 'error', 'message' => 'Parse Error: ' . $e->getMessage()]);
    exit(1);
}

$traverser = new NodeTraverser();
$traverser->addVisitor(new CloningVisitor());
$clonedStmts = $traverser->traverse($stmts);

$traverser = new NodeTraverser();
$factory = new BuilderFactory();

// 🛠️ Action: Rename Class (Fix Naming Violation)
if ($action === 'rename_class') {
    $old = $params['old_name'] ?? null;
    $new = $params['new_name'] ?? null;
    if (!$old || !$new) {
        echo json_encode(['status' => 'error', 'message' => 'Missing old_name or new_name']);
        exit(1);
    }
    if ($old === $new) {
        echo json_encode(['status' => 'error', 'message' => 'New class name matches old name']);
        exit(1);
    }

    $classFound = false;
    $traverser->addVisitor(new class($old, $new, $classFound) extends NodeVisitorAbstract {
        private string $oldName;
        private string $newName;
        private $classFound;
        public function __construct(string $old, string $new, &$classFound) {
            $this->oldName = $old;
            $this->newName = $new;
            $this->classFound =& $classFound;
        }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Stmt\Class_ && $node->name->toString() === $this->oldName) {
                $node->name = new Node\Identifier($this->newName);
                $this->classFound = true;
            }
            // Update in-file references that exactly match the old identifier (best-effort)
            if ($node instanceof Name && $node->toString() === $this->oldName) {
                return new Name($this->newName);
            }
            return null;
        }
    });
}

// 🛠️ Action: Rename References (AST-based, avoids raw string replaces)
if ($action === 'rename_reference') {
    $old = $params['old_name'] ?? null;
    $new = $params['new_name'] ?? null;
    if (!$old || !$new) {
        echo json_encode(['status' => 'error', 'message' => 'Missing old_name or new_name']);
        exit(1);
    }

    // Normalize names without leading slash for comparisons
    $normOld = ltrim($old, '\\');
    $normNew = ltrim($new, '\\');

    $traverser->addVisitor(new class($normOld, $normNew) extends NodeVisitorAbstract {
        private string $oldName;
        private string $newName;

        public function __construct(string $old, string $new)
        {
            $this->oldName = $old;
            $this->newName = $new;
        }

        private function matches(Name $name): bool
        {
            return ltrim($name->toString(), '\\') === $this->oldName;
        }

        public function enterNode(Node $node)
        {
            // use statements
            if ($node instanceof UseUse && $node->name instanceof Name && $this->matches($node->name)) {
                $node->name = new Name($this->newName);
                // If alias matches old, update it too
                if ($node->alias && $node->alias->toString() === $this->oldName) {
                    $node->alias = new Node\Identifier($this->newName);
                }
                return null;
            }

            // Trait use
            if ($node instanceof TraitUse) {
                foreach ($node->traits as &$trait) {
                    if ($trait instanceof Name && $this->matches($trait)) {
                        $trait = new Name($this->newName);
                    }
                }
                return null;
            }

            // Trait alias adaptations
            if ($node instanceof TraitAlias) {
                if ($node->trait instanceof Name && $this->matches($node->trait)) {
                    $node->trait = new Name($this->newName);
                }
                return null;
            }

            // Fully qualified / relative names anywhere in the AST
            if ($node instanceof Name && $this->matches($node)) {
                return new Name($this->newName);
            }

            // String-based class references (best-effort)
            if ($node instanceof String_) {
                $val = ltrim($node->value, '\\');
                if ($val === $this->oldName) {
                    return new String_($this->newName);
                }
            }
            return null;
        }
    });
}

// 🛠️ Action: Add Method (New Capability)
if ($action === 'add_method') {
    $targetClass = $params['target_class'] ?? '*';
    $methodName = $params['method_name'] ?? null;
    if (!$methodName) {
        echo json_encode(['status' => 'error', 'message' => 'Missing method_name']);
        exit(1);
    }

    $bodyStmts = [];
    if (!empty($params['content'])) {
        try {
            $bodyStmts = parseMethodBody($params['content'], $parser);
        } catch (Error $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit(1);
        }
    }

    $classFound = false;
    $methodAdded = false;
    $traverser->addVisitor(new class($targetClass, $methodName, $bodyStmts, $classFound, $methodAdded) extends NodeVisitorAbstract {
        private string $target;
        private string $method;
        private array $body;
        private $classFound;
        private $methodAdded;
        public function __construct(string $t, string $m, array $body, &$classFound, &$methodAdded) {
            $this->target = $t;
            $this->method = $m;
            $this->body = $body;
            $this->classFound =& $classFound;
            $this->methodAdded =& $methodAdded;
        }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Stmt\Class_ && ($this->target === '*' || $node->name->toString() === $this->target)) {
                $this->classFound = true;
                // Prevent duplicates
                foreach ($node->getMethods() as $method) {
                    if ($method->name->toString() === $this->method) {
                        throw new Error("Method {$this->method} already exists in class {$node->name->toString()}");
                    }
                }
                $factory = new BuilderFactory();
                $builder = $factory->method($this->method)->makePublic();
                if ($this->body) {
                    foreach ($this->body as $stmt) {
                        $builder->addStmt($stmt);
                    }
                }
                $newMethod = $builder->getNode();
                $node->stmts[] = $newMethod;
                $this->methodAdded = true;
            }
            return null;
        }
    });
}

// 🪄 Apply Transformations
try {
    $newStmts = $traverser->traverse($clonedStmts);
} catch (Error $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit(1);
}

// 🖨️ Print Code (Format Preserving)
$printer = new PrettyPrinter\Standard();
$newCode = $printer->printFormatPreserving($newStmts, $clonedStmts, $tokens);

// Write back or output
if (isset($params['dry_run']) && $params['dry_run']) {
    echo json_encode(['status' => 'success', 'code' => $newCode]);
} else {
    // Ensure an actual change occurred for add/rename
    if ($action === 'rename_class' && isset($classFound) && !$classFound) {
        echo json_encode(['status' => 'error', 'message' => 'Target class not found for rename']);
        exit(1);
    }
    if ($action === 'add_method' && isset($classFound) && !$classFound) {
        echo json_encode(['status' => 'error', 'message' => 'Target class not found for method injection']);
        exit(1);
    }
    if ($action === 'add_method' && isset($methodAdded) && !$methodAdded) {
        echo json_encode(['status' => 'error', 'message' => 'Method insertion aborted']);
        exit(1);
    }

    file_put_contents($filePath, $newCode);

    // If a class was renamed, best-effort rename file to preserve PSR-4 convention
    if ($action === 'rename_class' && empty($params['dry_run'])) {
        $pathInfo = pathinfo($filePath);
        $old = $params['old_name'] ?? '';
        $new = $params['new_name'] ?? '';
        if ($old && $new && strcasecmp($pathInfo['filename'] ?? '', $old) === 0) {
            $newPath = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $new . '.php';
            @rename($filePath, $newPath);
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'File patched successfully']);
}
