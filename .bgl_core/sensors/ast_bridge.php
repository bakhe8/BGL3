<?php

require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

// Check arguments
if ($argc < 2) {
    echo json_encode(['status' => 'error', 'message' => 'No file provided']);
    exit(1);
}

$filePath = $argv[1];

if (!file_exists($filePath)) {
    echo json_encode(['status' => 'error', 'message' => 'File not found']);
    exit(1);
}

// Read code
$code = file_get_contents($filePath);

// Initialize Parser
// Use v5.0 API
$parser = (new ParserFactory)->createForNewestSupportedVersion();

try {
    $stmts = $parser->parse($code);
} catch (Error $e) {
    echo json_encode(['status' => 'error', 'message' => 'Parse Error: ' . $e->getMessage()]);
    exit(1);
}

// Custom Visitor with Stack Context
class SensorVisitor extends NodeVisitorAbstract
{
    private $stack = [];
    public $findings = []; // Top level nodes (Classes/Functions)

    public function beforeTraverse(array $nodes)
    {
        $this->findings[] = [
            'type' => 'root',
            'name' => 'global',
            'calls' => [],
            'line' => 1
        ];
        $this->stack = [
            ['type' => 'root', 'children' => &$this->findings[0]['calls']]
        ];
    }

    public function enterNode(Node $node)
    {
        $currentContext = &$this->stack[count($this->stack) - 1];

        // Definition: Class
        if ($node instanceof Node\Stmt\Class_) {
            $classNode = [
                'type' => 'class',
                'name' => $node->name ? $node->name->toString() : 'anonymous',
                'extends' => $node->extends ? $node->extends->toString() : null,
                'line' => $node->getStartLine(),
                'methods' => []
            ];
            // Add to current siblings (Root)
            $currentContext['children'][] = &$classNode;

            // Push to stack as new context for methods
            $this->stack[] = ['type' => 'class', 'children' => &$classNode['methods']];
            return;
        }

        // Definition: Method
        if ($node instanceof Node\Stmt\ClassMethod) {
            $methodNode = [
                'type' => 'method',
                'name' => $node->name->toString(),
                'visibility' => $this->getVisibility($node),
                'line' => $node->getStartLine(),
                'calls' => [],
                'params' => []
            ];

            // Extract Typehints for Constructor (High Confidence Evidence)
            if ($node->name->toString() === '__construct') {
                foreach ($node->params as $param) {
                    if ($param->type instanceof Node\Name) {
                        $methodNode['params'][] = [
                            'name' => $param->var->name,
                            'type' => $param->type->toString(),
                            'evidence' => 'constructor_typehint'
                        ];
                    }
                }
            }

            // Add to class methods
            $currentContext['children'][] = &$methodNode;

            // Push to stack as new context for calls
            $this->stack[] = ['type' => 'method', 'children' => &$methodNode['calls']];
            return;
        }

        // Behavior: Calls (Only if inside method or root script)
        if ($currentContext['type'] === 'method' || $currentContext['type'] === 'root') {
            if ($node instanceof Node\Expr\MethodCall) {
                $caller = 'unknown';
                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    $caller = '$' . $node->var->name;
                }
                $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic';

                $currentContext['children'][] = [
                    'type' => 'method_call',
                    'caller' => $caller,
                    'method' => $methodName,
                    'line' => $node->getStartLine()
                ];
            }

            if ($node instanceof Node\Expr\StaticCall) {
                $class = $node->class instanceof Node\Name ? $node->class->toString() : 'dynamic';
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic';
                $currentContext['children'][] = [
                    'type' => 'static_call',
                    'class' => $class,
                    'method' => $method,
                    'line' => $node->getStartLine()
                ];
            }

            // Detect Instantiations (e.g., new GuaranteeRepository())
            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                $currentContext['children'][] = [
                    'type' => 'instantiation',
                    'class' => $node->class->toString(),
                    'line' => $node->getStartLine()
                ];
            }

            // Detect Laravel app() helper (Medium Confidence Evidence)
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                if ($node->name->toString() === 'app' && isset($node->args[0])) {
                    $arg = $node->args[0]->value;
                    if ($arg instanceof Node\Scalar\String_) {
                        $currentContext['children'][] = [
                            'type' => 'app_helper',
                            'target' => $arg->value,
                            'evidence' => 'app_helper_call',
                            'line' => $node->getStartLine()
                        ];
                    }
                }
            }
        }
    }

    public function leaveNode(Node $node)
    {
        // Pop stack when leaving Class or Method
        if ($node instanceof Node\Stmt\Class_) {
            array_pop($this->stack);
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            array_pop($this->stack);
        }
    }

    private function getVisibility(Node\Stmt\ClassMethod $node)
    {
        if ($node->isPublic())
            return 'public';
        if ($node->isProtected())
            return 'protected';
        if ($node->isPrivate())
            return 'private';
        return 'public';
    }
}

$visitor = new SensorVisitor();
$traverser = new NodeTraverser;
$traverser->addVisitor($visitor);

// Traverse
$traverser->traverse($stmts);

// Output
echo json_encode([
    'status' => 'success',
    'file' => $filePath,
    'data' => $visitor->findings
], JSON_PRETTY_PRINT);
