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

namespace blugin\tool\dev\builder;

use blugin\tool\dev\BluginTools;
use blugin\tool\dev\builder\printer\IPrinter;
use blugin\tool\dev\builder\printer\ShortenPrinter;
use blugin\tool\dev\builder\printer\StandardPrinter;
use blugin\tool\dev\builder\renamer\MD5Renamer;
use blugin\tool\dev\builder\renamer\Renamer;
use blugin\tool\dev\builder\renamer\SerialRenamer;
use blugin\tool\dev\builder\renamer\ShortenRenamer;
use blugin\tool\dev\builder\renamer\SpaceRenamer;
use blugin\tool\dev\builder\TraverserPriority as Priority;
use blugin\tool\dev\builder\visitor\CommentOptimizingVisitor;
use blugin\tool\dev\builder\visitor\ImportForcingVisitor;
use blugin\tool\dev\builder\visitor\ImportGroupingVisitor;
use blugin\tool\dev\builder\visitor\ImportRemovingVisitor;
use blugin\tool\dev\builder\visitor\ImportRenamingVisitor;
use blugin\tool\dev\builder\visitor\ImportSortingVisitor;
use blugin\tool\dev\builder\visitor\LocalVariableRenamingVisitor;
use blugin\tool\dev\builder\visitor\PrivateConstRenamingVisitor;
use blugin\tool\dev\builder\visitor\PrivateMethodRenamingVisitor;
use blugin\tool\dev\builder\visitor\PrivatePropertyRenamingVisitor;
use blugin\tool\dev\utils\Utils;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use pocketmine\command\PluginCommand;
use pocketmine\plugin\PluginBase;

class BluginBuilder{
    /** @var BluginTools */
    private $tools;

    public const RENAMER_SHORTEN = "shorten";
    public const RENAMER_SERIAL = "serial";
    public const RENAMER_SPACE = "space";
    public const RENAMER_MD5 = "md5";
    /** @var Renamer[] renamer tag -> renamer instance */
    private $renamers = [];

    public const PRINTER_STANDARD = "standard";
    public const PRINTER_SHORTEN = "shorten";
    /** @var IPrinter[] printer tag -> printer instance */
    private $printers = [];

    /** @var NodeTraverser[] traverser priority => NodeTraverser */
    private $traversers;

    /** @var string */
    private $printerMode = self::PRINTER_STANDARD;

    public function __construct(BluginTools $tools){
        $this->tools = $tools;

        $this->registerRenamer(self::RENAMER_SHORTEN, new ShortenRenamer());
        $this->registerRenamer(self::RENAMER_SERIAL, new SerialRenamer());
        $this->registerRenamer(self::RENAMER_SPACE, new SpaceRenamer());
        $this->registerRenamer(self::RENAMER_MD5, new MD5Renamer());

        $this->registerPrinter(self::PRINTER_STANDARD, new StandardPrinter());
        $this->registerPrinter(self::PRINTER_SHORTEN, new ShortenPrinter());

        //Load pre-processing settings
        foreach(Priority::ALL as $priority){
            $this->traversers[$priority] = new NodeTraverser();
        }
    }

    public function init(){
        $config = $this->getTools()->getConfig();

        if($config->getNested("preprocessing.comment-optimizing", true)){
            $this->registerVisitor(Priority::NORMAL, new CommentOptimizingVisitor());
        }

        //Load renaming mode settings
        foreach([
            "local-variable" => LocalVariableRenamingVisitor::class,
            "private-property" => PrivatePropertyRenamingVisitor::class,
            "private-method" => PrivateMethodRenamingVisitor::class,
            "private-const" => PrivateConstRenamingVisitor::class
        ] as $key => $class){
            if(isset($this->renamers[$mode = $config->getNested("preprocessing.renaming.$key", "serial")])){
                $this->registerVisitor(Priority::NORMAL, new $class(clone $this->renamers[$mode]));
            }
        }

        //Load import processing mode settings
        $mode = $config->getNested("preprocessing.importing.renaming", "serial");
        if($mode === "resolve"){
            $this->registerVisitor(Priority::HIGH, new ImportRemovingVisitor());
        }else{
            if(isset($this->renamers[$mode])){
                $this->registerVisitor(Priority::HIGH, new ImportRenamingVisitor(clone $this->renamers[$mode]));
            }
            if($config->getNested("preprocessing.importing.forcing", true)){
                $this->registerVisitor(Priority::NORMAL, new ImportForcingVisitor());
            }
            if($config->getNested("preprocessing.importing.grouping", true)){
                $this->registerVisitor(Priority::HIGHEST, new ImportGroupingVisitor());
            }
            if($config->getNested("preprocessing.importing.sorting", true)){
                $this->registerVisitor(Priority::HIGHEST, new ImportSortingVisitor());
            }
        }

        //Load build settings
        $this->printerMode = $config->getNested("build.print-format");

        $command = $this->getTools()->getCommand("bluginbuilder");
        if($command instanceof PluginCommand){
            $command->setExecutor(new BuildCommandExecutor($this));
        }
    }

    /** @throws \ReflectionException */
    public function buildPlugin(PluginBase $plugin) : void{
        $reflection = new \ReflectionClass(PluginBase::class);
        $fileProperty = $reflection->getProperty("file");
        $fileProperty->setAccessible(true);
        $sourcePath = rtrim(realpath($fileProperty->getValue($plugin)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $pharPath = "{$this->getTools()->getDataFolder()}{$plugin->getName()}_v{$plugin->getDescription()->getVersion()}.phar";

        $description = $plugin->getDescription();
        $metadata = [
            "name" => $description->getName(),
            "version" => $description->getVersion(),
            "main" => $description->getMain(),
            "api" => $description->getCompatibleApis(),
            "depend" => $description->getDepend(),
            "description" => $description->getDescription(),
            "authors" => $description->getAuthors(),
            "website" => $description->getWebsite(),
            "creationDate" => time()
        ];
        $this->buildPhar($sourcePath, $pharPath, $metadata);
    }

    /** @param mixed[] $metadata */
    public function buildPhar(string $filePath, string $pharPath, array $metadata) : void{
        //Remove the existing PHAR file
        if(file_exists($pharPath)){
            try{
                \Phar::unlinkArchive($pharPath);
            }catch(\Exception $e){
                unlink($pharPath);
            }
        }

        //Remove the existing build folder and remake
        $buildPath = "{$this->getTools()->getDataFolder()}build/";
        Utils::clearDirectory($buildPath);

        //Pre-build processing execution
        $config = $this->getTools()->getConfig();
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath, \FilesystemIterator::SKIP_DOTS)) as $path => $fileInfo){
            if($config->getNested("build.include-minimal", true)){
                $inPath = substr($path, strlen($filePath));
                if($inPath !== "plugin.yml" && strpos($inPath, "src\\") !== 0 && strpos($inPath, "resources\\") !== 0)
                    continue;
            }

            $out = substr_replace($path, $buildPath, 0, strlen($filePath));
            $outDir = dirname($out);
            if(!file_exists($outDir)){
                mkdir($outDir, 0777, true);
            }

            if(preg_match("/([a-zA-Z0-9]*)\.php$/", $path, $matchs)){
                try{
                    $contents = file_get_contents($fileInfo->getPathName());
                    $originalStmts = $parser->parse($contents);
                    $originalStmts = $this->traversers[Priority::BEFORE_SPLIT]->traverse($originalStmts);

                    $files = [$matchs[1] => $originalStmts];
                    if($config->getNested("preprocessing.spliting", true)){
                        $files = CodeSpliter::splitNodes($originalStmts, $matchs[1]);
                    }

                    foreach($files as $filename => $stmts){
                        foreach(Priority::DEFAULTS as $priority){
                            $stmts = $this->traversers[$priority]->traverse($stmts);
                        }
                        file_put_contents($outDir . DIRECTORY_SEPARATOR . $filename . ".php", $this->getPrinter()->print($stmts));
                    }
                }catch(\Error $e){
                    echo 'Parse Error: ', $e->getMessage();
                }
            }else{
                copy($path, $out);
            }
        }

        //Build the plugin with .phar file
        $phar = new \Phar($pharPath);
        $phar->setSignatureAlgorithm(\Phar::SHA1);
        if(!$config->getNested("build.skip-metadata", true)){
            $phar->setMetadata($metadata);
        }
        if(!$config->getNested("build.skip-stub", true)){
            $phar->setStub('<?php echo "PocketMine-MP plugin ' . "{$metadata["name"]}_v{$metadata["version"]}\nThis file has been generated using BluginBuilder at " . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();');
        }else{
            $phar->setStub("<?php __HALT_COMPILER();");
        }
        $phar->startBuffering();
        $phar->buildFromDirectory($buildPath);
        if(\Phar::canCompress(\Phar::GZ)){
            $phar->compressFiles(\Phar::GZ);
        }
        $phar->stopBuffering();
    }

    public function getTools() : BluginTools{
        return $this->tools;
    }

    /** @return NodeTraverser[] */
    public function getTraversers() : array{
        return $this->traversers;
    }

    public function getTraverser(int $priority = Priority::NORMAL) : ?NodeTraverser{
        return $this->traversers[$priority] ?? null;
    }

    public function registerVisitor(int $priority, NodeVisitorAbstract $visitor) : bool{
        $traverser = $this->getTraverser($priority);
        if($traverser === null)
            return false;

        $traverser->addVisitor($visitor);
        return true;
    }

    public function getPrinter(?string $mode = null) : IPrinter{
        return clone($this->printers[$mode] ?? $this->printers[$this->printerMode]);
    }

    public function registerPrinter(string $mode, IPrinter $printer) : void{
        $this->printers[$mode] = $printer;
    }

    /** @return Renamer[] */
    public function getRenamers() : array{
        return $this->renamers;
    }

    public function getRenamer(string $mode) : ?Renamer{
        return isset($this->renamers[$mode]) ? clone $this->renamers[$mode] : null;
    }

    public function registerRenamer(string $mode, Renamer $renamer) : void{
        $this->renamers[$mode] = $renamer;
    }
}