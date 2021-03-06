<?php

declare(strict_types=1);

namespace Rector\CakePHPToSymfony\Rector\Template;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use Rector\CakePHPToSymfony\TemplatePathResolver;
use Rector\PhpParser\Node\Resolver\NameResolver;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\PhpParser\NodeTraverser\CallableNodeTraverser;

final class TemplateMethodCallManipulator
{
    /**
     * @var ValueResolver
     */
    private $valueResolver;

    /**
     * @var TemplatePathResolver
     */
    private $templatePathResolver;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    /**
     * @var NameResolver
     */
    private $nameResolver;

    public function __construct(
        ValueResolver $valueResolver,
        TemplatePathResolver $templatePathResolver,
        CallableNodeTraverser $callableNodeTraverser,
        NameResolver $nameResolver
    ) {
        $this->valueResolver = $valueResolver;
        $this->templatePathResolver = $templatePathResolver;
        $this->callableNodeTraverser = $callableNodeTraverser;
        $this->nameResolver = $nameResolver;
    }

    public function refactorExistingRenderMethodCall(ClassMethod $classMethod): void
    {
        $controllerNamePart = $this->templatePathResolver->resolveClassNameTemplatePart($classMethod);

        $this->callableNodeTraverser->traverseNodesWithCallable($classMethod, function (Node $node) use (
            $controllerNamePart
        ) {
            $renderMethodCall = $this->matchThisRenderMethodCallBareOrInReturn($node);
            if ($renderMethodCall === null) {
                return null;
            }

            return $this->refactorRenderTemplateName($renderMethodCall, $controllerNamePart);
        });
    }

    private function refactorRenderTemplateName(Node $node, string $controllerNamePart): ?Return_
    {
        /** @var MethodCall $node */
        $renderArgumentValue = $this->valueResolver->getValue($node->args[0]->value);

        /** @var string|mixed $renderArgumentValue */
        if (! is_string($renderArgumentValue)) {
            return null;
        }

        if (Strings::contains($renderArgumentValue, '/')) {
            $templateName = $renderArgumentValue . '.twig';
        } else {
            // add explicit controller
            $templateName = $controllerNamePart . '/' . $renderArgumentValue . '.twig';
        }

        $node->args[0]->value = new String_($templateName);

        return new Return_($node);
    }

    private function isThisRenderMethodCall(Node $node): bool
    {
        if (! $node instanceof MethodCall) {
            return false;
        }

        if (! $this->nameResolver->isName($node->var, 'this')) {
            return false;
        }

        return $this->nameResolver->isName($node->name, 'render');
    }

    private function matchThisRenderMethodCallBareOrInReturn(Node $node): ?MethodCall
    {
        if ($node instanceof Return_) {
            $nodeExpr = $node->expr;
            if ($nodeExpr === null) {
                return null;
            }

            if (! $this->isThisRenderMethodCall($nodeExpr)) {
                return null;
            }

            /** @var MethodCall $nodeExpr */
            return $nodeExpr;
        }

        if ($node instanceof Expression) {
            if (! $this->isThisRenderMethodCall($node->expr)) {
                return null;
            }

            return $node->expr;
        }

        return null;
    }
}
