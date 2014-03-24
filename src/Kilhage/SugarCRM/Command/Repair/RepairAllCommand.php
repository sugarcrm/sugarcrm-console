<?php

namespace Kilhage\SugarCRM\Command\Repair;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Emil Kilhage
 */
class RepairAllCommand extends QuickRepairAndRebuildCommand
{

    protected function configure()
    {
        parent::configure();
        $this->setName("repair:all")
            ->setDescription("Repairs the extensions");
    }

    protected function getActions()
    {
        return array (
            "clearAll"
        );
    }

    protected function getMessage()
    {
        return "Running Quick Repair & Rebuild";
    }

}
