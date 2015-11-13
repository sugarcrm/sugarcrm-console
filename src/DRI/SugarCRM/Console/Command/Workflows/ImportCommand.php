<?php

namespace DRI\SugarCRM\Console\Command\Workflows;

use DRI\SugarCRM\Console\Command\ApplicationCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Emil Kilhage
 */
class ImportCommand extends ApplicationCommand
{
    /**
     * @var array
     */
    private static $links = array(
        'trigger_filters',
        'triggers',
        'alerts',
        'alerts',
        'actions',
    );

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('workflows:import');
        $this->addArgument('id', InputArgument::OPTIONAL, 'if you only want to import a single workflow');
        $this->addOption('directory', 'D', InputOption::VALUE_REQUIRED, 'target directive relative from the docroot', '../config/workflows');
        $this->addOption('purge', 'P', InputOption::VALUE_NONE, 'purges all workflows that does not exist in files');
        $this->setDescription('Export workflow records from .json files');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!is_dir($input->getOption('directory'))) {
            mkdir($input->getOption('directory'), 0755, true);
        }

        if (null !== $this->input->getArgument('id')) {
            $this->import($this->input->getArgument('id'));
        } else {
            $ids = $this->listIds();

            if (count($ids) > 0) {
                array_map(array ($this, 'import'), $ids);
            } else {
                $output->writeln('<info>no workflows to import</info>');
            }

            if ($input->getOption('purge')) {
                $this->purge($ids);
            }
        }
    }

    /**
     * @param array $ids
     */
    private function purge(array $ids)
    {
        $query = new \SugarQuery();
        $query->from(new \WorkFlow());
        $query->select('id');
        $query->where()->notIn('id', $ids);

        foreach ($query->execute() as $row) {
            $workflow = \BeanFactory::retrieveBean('WorkFlow', $row['id']);

            if ($workflow) {
                $this->output->writeln("<comment>- Deleting {$workflow->module_dir} with id {$workflow->id}</comment>");
                $workflow->mark_deleted($workflow->id);
            }
        }
    }

    /**
     * @return array
     * @throws \SugarQueryException
     */
    public function listIds()
    {
        $files =  glob(sprintf('%s/*.json', $this->input->getOption('directory')));

        return array_map(function ($file) {
            return basename($file, '.json');
        }, $files);
    }

    /**
     * @param string $id
     * @throws \Exception
     */
    public function import($id)
    {
        $file = sprintf('%s/%s.json', $this->input->getOption('directory'), $id);

        if (!file_exists($file)) {
            throw new \Exception("Unable to find file: $file");
        }

        $this->output->writeln("<comment>- Importing WorkFlow with id $id</comment>");

        $data = $this->readFile($file);

        /** @var \WorkFlow $workflow */
        $workflow = $this->findRecord('WorkFlow', $id);

        $changes = $this->populateData($workflow, $data);

        $this->saveRecord($workflow, $changes);

        foreach ($data as $fieldName => $value) {
            if (in_array($fieldName, self::$links)) {
                $this->syncLink($workflow, $fieldName, $value);
            }
        }
    }

    /**
     * @param \WorkFlow $workflow
     * @param string    $link
     * @param array     $records
     */
    private function syncLink(\WorkFlow $workflow, $link, array $records)
    {
        $workflow->load_relationship($link);

        $current = $workflow->$link->getBeans();

        foreach ($records as $id => $data) {
            $record = $this->findRecord($workflow->$link->getRelatedModuleName(), $id);

            if (isset($current[$id])) {
                unset($current[$id]);
            }

            $changes = $this->populateData($record, $data);

            $this->saveRecord($record, $changes);
        }

        $this->deleteRecords($current);
    }

    /**
     * @param \SugarBean $bean
     * @param array      $data
     * @return bool
     */
    private function populateData(\SugarBean $bean, array $data)
    {
        $changes = false;

        foreach ($data as $fieldName => $value) {
            if (!in_array($fieldName, self::$links)) {
                if ($bean->$fieldName != $value) {

                    if ($this->input->getOption('verbose')) {
                        $this->output->writeln("<comment>   * updating field $fieldName to $value on record with id {$bean->id} in module {$bean->module_dir}</comment>");
                    }

                    $bean->$fieldName = $value;
                    $changes = true;
                }
            }
        }

        return $changes;
    }

    /**
     * @param \SugarBean $bean
     * @param bool       $changes
     */
    private function saveRecord(\SugarBean $bean, $changes)
    {
        if ($bean->new_with_id) {
            $this->output->writeln("<comment>   * creating {$bean->module_dir} with id {$bean->id}</comment>");
            $bean->save();
        } elseif ($changes) {
            $this->output->writeln("<comment>   * updating {$bean->module_dir} with id {$bean->id}</comment>");
            $bean->save();
        } else {
            $this->output->writeln("<info>   * {$bean->module_dir} with id {$bean->id} is already synchronized</info>");
        }
    }

    /**
     * @param string $moduleName
     * @param string $id
     * @return \SugarBean
     */
    private function findRecord($moduleName, $id)
    {
        $workflow = \BeanFactory::retrieveBean($moduleName, $id, array(), false);

        if (null === $workflow) {
            $workflow = \BeanFactory::newBean($moduleName);
            $workflow->id = $id;
            $workflow->new_with_id = true;
        } elseif ($workflow->deleted === 1) {
            $workflow->deleted = 0;
        }

        return $workflow;
    }

    /**
     * @param \SugarBean[] $records
     */
    private function deleteRecords(array $records)
    {
        foreach ($records as $record) {
            $this->output->writeln("<comment>   * deleting {$record->module_dir} with id {$record->id}</comment>");
            $record->mark_deleted($record->id);
        }
    }

    /**
     * @param string $file
     * @return array
     * @throws \Exception
     */
    private function readFile($file)
    {
        $content = file_get_contents($file);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('json parse error: ' . json_last_error_msg());
        }

        return $data;
    }
}
