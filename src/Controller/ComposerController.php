<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         DebugKit 3.6.1
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace DebugKit\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\View\JsonView;
use Composer\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Provides utility features need by the toolbar.
 */
class ComposerController extends Controller
{

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('RequestHandler');
        $this->viewBuilder()->setClassName(JsonView::class);
    }

    /**
     * Before filter handler.
     *
     * @param \Cake\Event\Event $event The event.
     * @return void
     * @throws \Cake\Http\Exception\NotFoundException
     */
    public function beforeFilter(EventInterface $event)
    {
        if (!Configure::read('debug')) {
            throw new NotFoundException();
        }
    }

    /**
     * Check outdated composer dependencies
     *
     * @return void
     * @throws \RuntimeException
     */
    public function checkDependencies()
    {
        $this->request->allowMethod('post');

        $input = new ArrayInput([
            'command' => 'outdated',
            '--no-interaction' => true,
            '--direct' => (bool)$this->request->data('direct'),
        ]);

        $output = $this->executeComposerCommand($input);
        $dependencies = array_filter(explode("\n", $output->fetch()));
        $packages = [];
        foreach ($dependencies as $dependency) {
            if (strpos($dependency, 'php_network_getaddresses') !== false) {
                throw new \RuntimeException(__d('debug_kit', 'You have to be connected to the internet'));
            }
            if (strpos($dependency, '<highlight>') !== false) {
                $packages['semverCompatible'][] = $dependency;
                continue;
            }
            $packages['bcBreaks'][] = $dependency;
        }
        if (!empty($packages['semverCompatible'])) {
            $packages['semverCompatible'] = trim(implode("\n", $packages['semverCompatible']));
        }
        if (!empty($packages['bcBreaks'])) {
            $packages['bcBreaks'] = trim(implode("\n", $packages['bcBreaks']));
        }

        $this->set([
            '_serialize' => ['packages'],
            'packages' => $packages,
        ]);
    }

    /**
     * @param ArrayInput $input An array describing the command input
     * @return BufferedOutput Aa Console command buffered result
     */
    private function executeComposerCommand(ArrayInput $input)
    {
        $bin = implode(DIRECTORY_SEPARATOR, [ROOT, 'vendor', 'bin', 'composer']);
        putenv('COMPOSER_HOME=' . $bin);
        putenv('COMPOSER_CACHE_DIR=' . CACHE);

        $dir = getcwd();
        chdir(ROOT);
        $timeLimit = ini_get('max_execution_time');
        set_time_limit(300);
        $memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        $output = new BufferedOutput();
        $application = new Application();
        $application->setAutoExit(false);
        $application->run($input, $output);

        // Restore environment
        chdir($dir);
        set_time_limit($timeLimit);
        ini_set('memory_limit', $memoryLimit);

        return $output;
    }
}
