<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud, GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Tests\Core\Command\Group;

use OC\Core\Command\Group\SyncBackend;
use OC\Group\BackendGroup;
use OC\Group\GroupMapper;
use OC\Group\Manager as GroupManager;
use OC\User\Manager as UserManager;
use OCP\GroupInterface;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\UserInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;
use Test\Util\Group\MemoryGroupMapper;

class SyncBackendTest extends TestCase {

	/** @var GroupManager | \PHPUnit_Framework_MockObject_MockObject */
	private $groupManager;

	/** @var UserManager | \PHPUnit_Framework_MockObject_MockObject */
	private $userManager;

	/** @var MemoryGroupMapper | \PHPUnit_Framework_MockObject_MockObject */
	private $groupMapper;

	/** @var GroupInterface | \PHPUnit_Framework_MockObject_MockObject */
	private $backend;

	/** @var UserInterface | \PHPUnit_Framework_MockObject_MockObject */
	private $userBackend1;
	/** @var UserInterface | \PHPUnit_Framework_MockObject_MockObject */
	private $userBackend2;

	/** @var CommandTester */
    private $commandTester;

	/** @var \PHPUnit_Framework_MockObject_MockObject */
	protected $consoleInput;

	/** @var \PHPUnit_Framework_MockObject_MockObject */
	protected $consoleOutput;

	/** @var \Symfony\Component\Console\Command\Command */
	protected $command;

	/** @var IGroup | \PHPUnit_Framework_MockObject_MockObject */
	private $group1;
	/** @var IGroup | \PHPUnit_Framework_MockObject_MockObject */
	private $group2;
	/** @var IUser | \PHPUnit_Framework_MockObject_MockObject */
	private $user11;
	/** @var IUser | \PHPUnit_Framework_MockObject_MockObject */
	private $user21;
	/** @var IUser | \PHPUnit_Framework_MockObject_MockObject */
	private $user22;
	/** @var IUser | \PHPUnit_Framework_MockObject_MockObject */
	private $user23;

    protected function setUp() {
        parent::setUp();
		$this->groupMapper = new MemoryGroupMapper($this->createMock(IDBConnection::class));
		$this->userManager = $this->createMock(UserManager::class);
		$this->groupManager = $this->createMock(GroupManager::class);
		$this->backend = $this->createMock(GroupInterface::class);
		$this->userBackend1 = $this->getMockBuilder(UserInterface::class)
			->setMockClassName('UserBackend1')->getMock();
		$this->userBackend2 = $this->getMockBuilder(UserInterface::class)
			->setMockClassName('UserBackend2')->getMock();

		$this->command = new SyncBackend($this->groupMapper,
			\OC::$server->getConfig(),
			\OC::$server->getLogger(),
			$this->groupManager,
			$this->userManager);
        $this->commandTester = new CommandTester($this->command);
		$this->consoleInput = $this->createMock(InputInterface::class);
		$this->consoleOutput = $this->createMock(OutputInterface::class);

		$this->user11 = $this->getMockBuilder(IUser::class)
			->setMockClassName('user11')->getMock();
		$this->user11->expects($this->any())->method('getBackendClassName')->willReturn(get_class($this->userBackend1));
		$this->user11->expects($this->any())->method('getUID')->willReturn('user11');

		$this->user21 = $this->getMockBuilder(IUser::class)
			->setMockClassName('user21')->getMock();
		$this->user21->expects($this->any())->method('getBackendClassName')->willReturn(get_class($this->userBackend1));
		$this->user21->expects($this->any())->method('getUID')->willReturn('user21');

		$this->user22 = $this->getMockBuilder(IUser::class)
			->setMockClassName('user22')->getMock();
		$this->user22->expects($this->any())->method('getBackendClassName')->willReturn(get_class($this->userBackend2));
		$this->user22->expects($this->any())->method('getUID')->willReturn('user22');

		$this->user23 = $this->getMockBuilder(IUser::class)
			->setMockClassName('user23')->getMock();
		$this->user23->expects($this->any())->method('getBackendClassName')->willReturn(get_class($this->userBackend1));
		$this->user23->expects($this->any())->method('getUID')->willReturn('user23');

		$this->group1 = $this->getMockBuilder(IGroup::class)
			->setMockClassName('group1')->getMock();
		$this->group1->expects($this->any())->method('getGID')->willReturn('group1');

		$this->group2 = $this->getMockBuilder(IGroup::class)
			->setMockClassName('group2')->getMock();
		$this->group2->expects($this->any())->method('getGID')->willReturn('group2');
	}

	public function tearDown() {
		$this->groupMapper->clear();
		parent::tearDown();
	}

	public function testList() {
		$this->consoleInput->method('getOption')
			->will($this->returnValueMap([
				['list', true]
			]));

		$this->consoleOutput->expects($this->once())
			->method('writeln');

		$this->groupManager->expects($this->once())
			->method('getBackends')->willReturn([$this->backend]);

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
    }

//	/**
//	 * There are user and groups synced. However, memberships changed
//	 */
//	public function testSyncMemberships() {
//		$this->groupManager->expects($this->once())
//			->method('getBackends')->willReturn([$this->backend]);
//		$this->userManager->expects($this->any())
//			->method('getBackends')->willReturn([$this->userBackend1, $this->userBackend2]);
//
//		// Insert first some existing group into internal mapping
//		$backendGroup = new BackendGroup();
//		$backendGroup->setGroupId($this->group1->getGID());
//		$backendGroup->setDisplayName($this->group1->getGID());
//		$backendGroup->setBackend(get_class($this->backend));
//		$this->groupMapper->insert($backendGroup);
//		$backendGroup = new BackendGroup();
//		$backendGroup->setGroupId($this->group2->getGID());
//		$backendGroup->setDisplayName($this->group2->getGID());
//		$backendGroup->setBackend(get_class($this->backend));
//		$this->groupMapper->insert($backendGroup);
//
//		$this->backend->expects($this->at(0))
//			->method('getGroups')
//			->with('', 500, 0)
//			->will($this->returnValue([$this->group1->getGID(), $this->group2->getGID()]));
//
//		// Does not implement group details
//		$this->backend->expects($this->any())
//			->method('implementsActions')
//			->willReturn(false);
//
//		// This backend has only user11
//		// It is expected to be added to internal mapping
//		$this->backend->expects($this->at(3))
//			->method('usersInGroup')
//			->with('group1', '', 500, 0)
//			->willReturn([$this->user11->getUID()]);
//
//		$this->group1->expects($this->any())
//			->method('getUsers')->willReturn([]);
//
//		$this->userManager->expects($this->at(0))
//			->method('userExists')->with($this->user11->getUID())->willReturn(true);
//		$this->userManager->expects($this->at(1))
//			->method('get')->with($this->user11->getUID())->willReturn($this->user11);
//
//		$this->groupManager->expects($this->at(1))
//			->method('get')->willReturn($this->group1);
//		$this->groupManager->expects($this->at(2))
//			->method('get')->willReturn($this->group1);
//
//		$this->userManager->expects($this->at(2))
//			->method('get')->with($this->user11->getUID())->willReturn($this->user11);
//
//		$this->group1->expects($this->once())
//			->method('addUser')->with($this->user11)->willReturn(true);
//
//		// This backend user21, but now it was removed from remote backend
//		// It is expected to be removed from internal mapping. Backend also had one user user23 which
//		// needs to be added. Additionally, in internal mapping user22 (from another backend) is present, and
//		// is not expected to be affected.
//		$this->backend->expects($this->at(4))
//			->method('usersInGroup')
//			->with('group2', '', 500, 0)
//			->willReturn([$this->user23->getUID()]);
//		$this->group2->expects($this->any())
//			->method('getUsers')->willReturn([$this->user21, $this->user22]);
//
//		$this->userManager->expects($this->at(3))
//			->method('userExists')->with($this->user23->getUID())->willReturn(true);
//		$this->userManager->expects($this->at(4))
//			->method('get')->with($this->user23->getUID())->willReturn($this->user23);
//
//		$this->groupManager->expects($this->at(3))
//			->method('get')->willReturn($this->group2);
//		$this->groupManager->expects($this->at(4))
//			->method('get')->willReturn($this->group2);
//
//		$this->userManager->expects($this->at(5))
//			->method('get')->with($this->user21->getUID())->willReturn($this->user21);
//
//		$this->group2->expects($this->once())
//			->method('removeUser')->with($this->user21)->willReturn(true);
//
//		$this->userManager->expects($this->at(6))
//			->method('get')->with($this->user23->getUID())->willReturn($this->user23);
//
//		$this->group2->expects($this->once())
//			->method('addUser')->with($this->user23)->willReturn(true);
//
//		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
//		$this->assertCount(2, $backendGroups);
//
//		$this->commandTester->execute(['backend-class' => get_class($this->backend)]);
//		$output = $this->commandTester->getDisplay();
//		$this->assertNotNull($output);
//		$this->assertContains('Count groups from external backend', $output);
//		$this->assertContains('Scan existing groups and find groups to delete', $output);
//		$this->assertContains('No groups to be deleted have been detected', $output);
//		$this->assertContains('Fetch remote users for fetched and synced groups', $output);
//		$this->assertContains('Sync memberships for synced groups', $output);
//
//		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
//		$this->assertCount(2, $backendGroups);
//	}

	/**
	 * There are user and groups synced. However, memberships has been removed
	 */
	public function testSyncMembershipRemoval() {
		$this->groupManager->expects($this->once())
			->method('getBackends')->willReturn([$this->backend]);
		$this->userManager->expects($this->any())
			->method('getBackends')->willReturn([$this->userBackend1, $this->userBackend2]);

		// Insert first some existing group into internal mapping
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId($this->group2->getGID());
		$backendGroup->setDisplayName($this->group2->getGID());
		$backendGroup->setBackend(get_class($this->backend));
		$this->groupMapper->insert($backendGroup);

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue([$this->group2->getGID()]));

		// Does not implement group details
		$this->backend->expects($this->any())
			->method('implementsActions')
			->willReturn(false);

		// TODO
		$this->backend->expects($this->at(2))
			->method('usersInGroup')
			->with('group2', '', 500, 0)
			->willReturn([]);
		$this->group2->expects($this->any())
			->method('getUsers')->willReturn([$this->user21, $this->user22, $this->user23]);

		$this->userManager->expects($this->at(2))
			->method('userExists')->with($this->user23->getUID())->willReturn(true);
		$this->userManager->expects($this->at(3))
			->method('get')->with($this->user23->getUID())->willReturn($this->user23);

		$this->groupManager->expects($this->at(1))
			->method('get')->willReturn($this->group2);
		$this->groupManager->expects($this->at(2))
			->method('get')->willReturn($this->group2);

		$this->userManager->expects($this->at(6))
			->method('get')->with($this->user21->getUID())->willReturn($this->user21);

		$this->group2->expects($this->once())
			->method('removeUser')->with($this->user21)->willReturn(true);

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);

		$this->commandTester->execute(['backend-class' => get_class($this->backend)]);
		$output = $this->commandTester->getDisplay();
		$this->assertNotNull($output);
		$this->assertContains('Count groups from external backend', $output);
		$this->assertContains('Scan existing groups and find groups to delete', $output);
		$this->assertContains('No groups to be deleted have been detected', $output);
		$this->assertContains('Fetch remote users for fetched and synced groups', $output);
		$this->assertContains('Sync memberships for synced groups', $output);

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
	}

	/**
	 * There are no user or groups synced. We expect groups to be synced down, but
	 * since there are no users existing in remote synced, no memberships will be added
	 */
	public function testSyncAllNew() {
		$this->groupManager->expects($this->once())
			->method('getBackends')->willReturn([$this->backend]);

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue([$this->group1->getGID()]));

		// Does not implement group details
		$this->backend->expects($this->any())
			->method('implementsActions')
			->willReturn(false);

		$this->backend->expects($this->at(2))
			->method('usersInGroup')
			->with('group1', '', 500, 0)
			->willReturn([$this->user11->getUID()]);

		$this->groupManager->expects($this->any())
			->method('get')->willReturn($this->group1);

		$this->group1->expects($this->any())->method('getUsers')->willReturn([]);

		$this->userManager->expects($this->any())->method('userExists')->willReturn(false);

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(0, $backendGroups);

		$this->commandTester->execute(['backend-class' => get_class($this->backend)]);
		$output = $this->commandTester->getDisplay();
		$this->assertNotNull($output);
		$this->assertContains('Count groups from external backend', $output);
		$this->assertContains('Scan existing groups and find groups to delete', $output);
		$this->assertContains('No groups to be deleted have been detected', $output);
		$this->assertContains('Fetch remote users for fetched and synced groups', $output);
		$this->assertContains('Sync memberships for synced groups', $output);

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
    }

	/**
	 * There are no user synced. There are groups synced, but they do not exist anymore in the backend.
	 * They are expected to be deleted from internal mapping.
	 */
	public function testSyncUnknownGroups() {
		$this->groupManager->expects($this->once())
			->method('getBackends')->willReturn([$this->backend]);

		// Insert first some existing group into internal mapping
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId($this->group1->getGID());
		$backendGroup->setDisplayName($this->group1->getGID());
		$backendGroup->setBackend(get_class($this->backend));
		$this->groupMapper->insert($backendGroup);

		// Make group manger return it also
		$this->groupManager->expects($this->any())
			->method('get')->willReturn($this->group1);

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue([]));

		// Does not implement group details
		$this->backend->expects($this->any())
			->method('implementsActions')
			->willReturn(false);

		$this->backend->expects($this->never())
			->method('usersInGroup');

		$this->group1->expects($this->never())->method('getUsers');

		$this->userManager->expects($this->never())->method('userExists');

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);

		$this->commandTester->execute(['backend-class' => get_class($this->backend)]);
		$output = $this->commandTester->getDisplay();
		$this->assertNotNull($output);
		$this->assertContains('Count groups from external backend', $output);
		$this->assertContains('Scan existing groups and find groups to delete', $output);
		$this->assertContains('Proceeding to remove the backend groups. Following groups are no longer known with the connected backend.', $output);
		$this->assertContains($this->group1->getGID(), $output);
		$this->assertContains('Fetch remote users for fetched and synced groups', $output);
		$this->assertContains('Sync memberships for synced groups', $output);

		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
	}

    /**
     * @dataProvider inputProvider
     * @param array $input
     * @param string $expectedOutput
     */
    public function testErrors($input, $expectedOutput) {
		$this->groupManager->expects($this->any())
			->method('getBackends')->willReturn([$this->backend]);

        $this->commandTester->execute($input);
        $output = $this->commandTester->getDisplay();
        $this->assertContains($expectedOutput, $output);
    }

    public function inputProvider() {
        return [
            [['backend-class' => 'OCA\User_LDAP\Group_Proxy'], 'does not exist'],
			[['backend-class' => null], 'No backend class name given'],
        ];
    }
}
