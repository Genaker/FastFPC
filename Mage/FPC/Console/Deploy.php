<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Mage\FPC\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Deploy extends Command
{

    const NAME_ARGUMENT = "name";
    const NAME_OPTION = "option";

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $name = $input->getArgument(self::NAME_ARGUMENT);
        $option = $input->getOption(self::NAME_OPTION);
        $includeFile = dirname(__DIR__) . "/FPC.php";
	    $cmd = "grep -r \"FPC.php\" " . BP . "/pub/index.php" . " || sed -i '2 i include \"$includeFile\";' " . BP . "/pub/index.php";
	    echo $cmd . "\n";
        exec($cmd, $output);
        var_dump($output);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("fpc:deploy");
        $this->setDescription("Deploy FPC to pub/index.php");
        $this->setDefinition([
            new InputArgument(self::NAME_ARGUMENT, InputArgument::OPTIONAL, "Name"),
            new InputOption(self::NAME_OPTION, "-a", InputOption::VALUE_NONE, "Option functionality")
        ]);
        parent::configure();
    }
}
