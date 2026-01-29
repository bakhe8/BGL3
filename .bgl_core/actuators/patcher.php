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
    $traverser->addVisitor(new class($params['old_name'], $params['new_name']) extends NodeVisitorAbstract {
        private $oldName;
        private $newName;
        public function __construct($old, $new) { $this->oldName = $old; $this->newName = $new; }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Stmt\Class_ && $node->name->toString() === $this->oldName) {
                $node->name = new Node\Identifier($this->newName);
            }
        }
    });
}

// 🛠️ Action: Add Method (New Capability)
if ($action === 'add_method') {
    $traverser->addVisitor(new class($params['target_class'], $params['method_name'], $params['content'] ?? '') extends NodeVisitorAbstract {
        private $target;
        private $method;
        private $content;
        public function __construct($t, $m, $c) { $this->target = $t; $this->method = $m; $this->content = $c; }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Stmt\Class_ && ($this->target === '*' || $node->name->toString() === $this->target)) {
                $factory = new BuilderFactory();
                $newMethod = $factory->method($this->method)
                    ->makePublic()
                    ->addStmt(new Node\Stmt\Expression(new Node\Expr\Print_(new Node\Scalar\String_("Added by Agent"))))
                    ->getNode();
                $node->stmts[] = $newMethod;
            }
        }
    });
}

// 🪄 Apply Transformations
$newStmts = $traverser->traverse($clonedStmts);

// 🖨️ Print Code (Format Preserving)
$printer = new PrettyPrinter\Standard();
$newCode = $printer->printFormatPreserving($newStmts, $clonedStmts, $tokens);

// Write back or output
if (isset($params['dry_run']) && $params['dry_run']) {
    echo json_encode(['status' => 'success', 'code' => $newCode]);
} else {
    file_put_contents($filePath, $newCode);
    echo json_encode(['status' => 'success', 'message' => 'File patched successfully']);
}
