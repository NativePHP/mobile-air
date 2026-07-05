<?php

namespace Native\Mobile\Edge;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class NativeDumpException extends \RuntimeException
{
    protected array $variables;

    protected string $sourceFile;

    protected int $sourceLine;

    public function __construct(array $variables, string $file, int $line)
    {
        $this->variables = $variables;
        $this->sourceFile = $file;
        $this->sourceLine = $line;

        parent::__construct('dd() called', 0, null);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getSourceFile(): string
    {
        return $this->sourceFile;
    }

    public function getSourceLine(): int
    {
        return $this->sourceLine;
    }

    public function getFormattedDumps(): string
    {
        $cloner = new VarCloner;
        $dumper = new CliDumper;
        $dumper->setColors(false);

        $output = '';

        foreach ($this->variables as $i => $var) {
            if (count($this->variables) > 1) {
                $output .= '--- Variable #'.($i + 1)." ---\n";
            }

            $data = $cloner->cloneVar($var);
            $output .= $dumper->dump($data, true);
            $output .= "\n";
        }

        return rtrim($output);
    }
}
