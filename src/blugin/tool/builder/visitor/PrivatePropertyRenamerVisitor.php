<?php

/*
 *
 *  ____  _             _         _____
 * | __ )| |_   _  __ _(_)_ __   |_   _|__  __ _ _ __ ___
 * |  _ \| | | | |/ _` | | '_ \    | |/ _ \/ _` | '_ ` _ \
 * | |_) | | |_| | (_| | | | | |   | |  __/ (_| | | | | | |
 * |____/|_|\__,_|\__, |_|_| |_|   |_|\___|\__,_|_| |_| |_|
 *                |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  Blugin team
 * @link    https://github.com/Blugin
 * @license https://www.gnu.org/licenses/lgpl-3.0 LGPL-3.0 License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 */

declare(strict_types=1);

namespace blugin\tool\builder\visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;

class PrivatePropertyRenamerVisitor extends RenamerHolderVisitor{
    /**
     * Rename private property on before traverse
     *
     * @param Node[] $nodes
     *
     * @return array
     **/
    public function beforeTraverse(array $nodes){
        $this->renamer->init();
        $this->renameProperties($nodes);

        return $nodes;
    }

    /**
     * Rename private property on enter
     *
     * @param Node $node
     *
     * @return Node|null
     */
    public function enterNode(Node $node){
        if($node instanceof PropertyFetch){
            if($this->renamer->rename($node->name) === null)
                return null;
            return $node;
        }
        return null;
    }

    /**
     * Rename private property with recursion
     *
     * @param Node[] $nodes
     *
     * @return void
     **/
    private function renameProperties(array $nodes) : void{
        foreach($nodes as $node){
            if($node instanceof Property && ($node->flags & Class_::MODIFIER_PRIVATE)){
                foreach($node->props as $prop){
                    $this->renamer->generate($prop->name);
                    $this->renamer->rename($prop->name);
                }
            }

            //Child node with recursion processing
            if(isset($node->stmts) && is_array($node->stmts)){
                $this->renameProperties($node->stmts);
            }
        }
    }
}