<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Moodle39\Dev;
use Moosh\MooshCommand;

class GenerateFilemanager extends MooshCommand
{
    public function __construct()
    {
        parent::__construct('filemanager', 'generate');
    }


    public function execute()
    {
        $vars = array('id' => $this->pluginInfo['type'] . '_' . $this->pluginInfo['name']);
        foreach (array('filemanager/form-handler.twig', 'filemanager/display.twig', 'filemanager/lib.twig') as $template) {
            echo render_template($this->mooshDir . '/templates/' . $template, $vars);
        }
    }
}
