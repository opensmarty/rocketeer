<?php

/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Rocketeer\Services\Connections;

use Rocketeer\Services\Bootstrapper\Bootstrapper;
use Rocketeer\Services\Bootstrapper\Modules\UserBootstrapper;
use Rocketeer\Services\Connections\Credentials\Keys\ConnectionKey;
use Rocketeer\TestCases\RocketeerTestCase;

class ConnectionsHandlerTest extends RocketeerTestCase
{
    public function testCanGetAvailableConnections()
    {
        $connections = $this->connections->getAvailableConnections();
        $this->assertEquals(['production', 'staging'], array_keys($connections));

        $this->localStorage->set('connections.custom.username', 'foobar');
        $connections = $this->connections->getAvailableConnections();
        $this->assertEquals(['production', 'staging', 'custom'], array_keys($connections));
    }

    public function testCanExpandPathsAtRuntime()
    {
        $this->swapConnections([
            'production' => [
                'host' => 'foo.com',
                'key' => '~/.ssh/id_rsa',
            ],
        ]);

        $this->assertEquals($this->paths->getUserHomeFolder().'/.ssh/id_rsa', $this->connections->getCurrentConnectionKey()->key);
    }

    public function testCanGetCurrentConnection()
    {
        $this->swapConfig(['default' => 'production']);
        $this->assertConnectionEquals('production');

        $this->swapConfig(['default' => 'staging']);
        $this->assertConnectionEquals('staging');
    }

    public function testCanChangeActiveConnection()
    {
        $this->assertConnectionEquals('production');

        $this->connections->setCurrentConnection('staging');
        $this->assertConnectionEquals('staging');

        $this->connections->setActiveConnections('staging,production');
        $this->assertEquals(['production', 'staging'], $this->connections->getActiveConnections()->keys()->all());
    }

    public function testFillsConnectionCredentialsHoles()
    {
        $connections = $this->connections->getAvailableConnections();
        $this->assertArrayHasKey('production', $connections);

        $this->localStorage->set('connections', [
            'staging' => [
                'host' => 'foobar',
                'username' => 'user',
                'password' => '',
                'keyphrase' => '',
                'key' => '/Users/user/.ssh/id_rsa',
                'agent' => '',
            ],
        ]);
        $connections = $this->connections->getAvailableConnections();
        $this->assertArrayHasKey('production', $connections);
    }

    public function testDoesNotResetConnectionIfSameAsCurrent()
    {
        /** @var UserBootstrapper $prophecy */
        $prophecy = $this->bindProphecy(UserBootstrapper::class, Bootstrapper::class);

        $this->connections->setCurrentConnection('staging');
        $this->connections->setCurrentConnection('staging');
        $this->connections->setCurrentConnection('staging');

        $prophecy->bootstrapUserCode()->shouldHaveBeenCalledTimes(1);
    }

    public function testDoesNotResetStageIfSameAsCurrent()
    {
        /** @var UserBootstrapper $prophecy */
        $prophecy = $this->bindProphecy(UserBootstrapper::class, Bootstrapper::class);

        $this->connections->setStage('foobar');
        $this->connections->setStage('foobar');
        $this->connections->setStage('foobar');

        $prophecy->bootstrapUserCode()->shouldHaveBeenCalledTimes(1);
    }

    public function testValidatesConnectionOnMultiset()
    {
        $this->connections->setActiveConnections(['production', 'bar']);

        $this->assertEquals(['production'], $this->connections->getActiveConnections()->keys()->all());
    }

    public function testDoesNotReuseConnectionIfDifferentServer()
    {
        $this->swapConnections([
            'staging' => [
                'servers' => [
                    [
                        'host' => 'foobar.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                    [
                        'host' => 'barbaz.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                ],
            ],
        ]);

        $this->connections->setCurrentConnection('staging', 0);
        $this->assertConnectionEquals('staging');
        $this->assertCurrentServerEquals(0);

        $this->connections->setCurrentConnection('staging', 1);
        $this->assertConnectionEquals('staging');
        $this->assertCurrentServerEquals(1);
    }

    /**
     * @expectedException \Rocketeer\Services\Connections\ConnectionException
     * @expectedExceptionMessage Invalid connection(s): foo, bar
     */
    public function testThrowsExceptionWhenTryingToSetInvalidConnection()
    {
        $this->connections->setActiveConnections('foo,bar');
    }

    public function testFiresEventWhenConnectedToServer()
    {
        $this->expectOutputString('connected');

        $this->connections->getCurrentConnection()->setConnected(false);

        $this->events->addListener('connected.production', function () {
            echo 'connected';
        });

        $this->swapConnections([
            'production' => [
                'host' => 'foobar.com',
                'username' => 'foobar',
                'password' => 'foobar',
            ],
        ]);

        $this->connections->getCurrentConnection();
    }

    public function testCanSetConnectionThroughConnectionKey()
    {
        $this->swapConnections([
            'staging' => [
                'servers' => [
                    [
                        'host' => 'foobar.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                    [
                        'host' => 'barbaz.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                ],
            ],
        ]);

        $this->connections->setCurrentConnection(new ConnectionKey([
            'name' => 'staging',
            'server' => 1,
        ]));

        $connectionKey = $this->connections->getCurrentConnection()->getConnectionKey();
        $this->assertEquals('barbaz.com', $connectionKey->host);
        $this->assertEquals(1, $connectionKey->server);
    }

    public function testCanCheckWhatCurrentConnectionIs()
    {
        $this->swapConnections([
            'production' => [
                'servers' => [
                    [
                        'host' => 'foobar.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                    [
                        'host' => 'barbaz.com',
                        'username' => 'foobar',
                        'password' => 'foobar',
                    ],
                ],
            ],
        ]);

        $this->connections->setCurrentConnection('production', 1);

        $this->assertTrue($this->connections->is('production', 1));
        $this->assertFalse($this->connections->is('production', 0));
        $this->assertTrue($this->connections->is('production'));
        $this->assertFalse($this->connections->is('staging'));
    }

    public function testCanUseRuntimeOptions()
    {
        $this->bindDummyCommand([
            '--key' => 'foobar',
        ]);

        $this->swapConnections([
            'production' => [
                'host' => 'foo.com',
                'key' => '~/.ssh/id_rsa',
            ],
        ]);

        $this->assertEquals('foobar', $this->connections->getCurrentConnectionKey()->key);
    }
}
