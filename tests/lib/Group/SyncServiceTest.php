<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
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

namespace Test\Group;

use OC\Group\BackendGroup;
use OC\Group\SyncService;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ILogger;
use Test\TestCase;
use Test\Util\Group\MemoryGroupMapper;

class SyncServiceTest extends TestCase {

	/** @var GroupInterface | \PHPUnit_Framework_MockObject_MockObject  */
	private $backend;

	/** @var MemoryGroupMapper */
	private $groupMapper;

	public function setUp() {
		parent::setUp();
		$this->backend = $this->getMockBuilder(GroupInterface::class)
			->disableOriginalConstructor()
			->setMethods([
				'getGroupDetails',
				'implementsActions',
				'getUserGroups',
				'inGroup',
				'getGroups',
				'groupExists',
				'usersInGroup',
				'createGroup',
				'deleteGroup',
				'addToGroup',
				'removeFromGroup',
				'isVisibleForScope',
			])
			->getMock();

		$this->groupMapper = new MemoryGroupMapper($this->createMock(IDBConnection::class));
	}

	public function tearDown() {
		$this->groupMapper->clear();
		parent::tearDown();
	}

	public function testCount() {
		$syncService = $this->getSyncService();

		for($n=0; $n < 3; $n++) {
			$groups = [];
			for ($i = 0; $i < 500; $i++) {
				$id = $n*500 + $i;
				$groups[] = 'group'.$id;
			}

			$this->backend->expects($this->at($n))
				->method('getGroups')
				->with('', 500, $n*500)
				->will($this->returnValue($groups));
		}
		$this->backend->expects($this->at(3))
			->method('getGroups')
			->with('', 500, $n*500)
			->will($this->returnValue(['groupbeforelast', 'grouplast']));

		// This should call backend 4 times and count of gids should be 1502
		$counter = 0;
		$syncService->count( function ($gid) use (&$counter){
			$counter++;
		});

		$this->assertEquals(1502, $counter);

		// This should call backend 0 times (fetch from cache). Count of gids should be 1502
		$counter = 0;
		$syncService->count( function ($gid) use (&$counter){
			$counter++;
		});

		$this->assertEquals(1502, $counter);
	}

	public function testGetRemoteGroupUsers() {
		$syncService = $this->getSyncService();

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue(['group1','group2']));

		$callNo = 1;
		for($n=0; $n < 3; $n++) {
			$users = [];
			for ($i = 0; $i < 500; $i++) {
				$id = $n*500 + $i;
				$users[] = 'user'.$id;
			}

			$this->backend->expects($this->at($callNo))
				->method('usersInGroup')
				->with('group1', '', 500, $n*500)
				->will($this->returnValue($users));

			$callNo++;
		}
		$this->backend->expects($this->at(4))
			->method('usersInGroup')
			->with('group1', '', 500, $n*500)
			->will($this->returnValue(['userbeforelast', 'userlast']));

		$this->backend->expects($this->at(5))
			->method('usersInGroup')
			->with('group2', '', 500, 0)
			->will($this->returnValue([]));

		$groups = [];
		$syncService->getRemoteGroupUsers( function ($gid) use (&$groups){
			$groups[] = $gid;
		});

		$this->assertEquals($groups[0], 'group1');
		$this->assertEquals($groups[1], 'group2');
		$this->assertCount(2, $groups);
	}

	public function testSync() {
		$syncService = $this->getSyncService();

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue(['group1','group3']));

		$this->backend->expects($this->any())
			->method('implementsActions')
			->willReturn(true);

		// Insert first some existing group into internal mapping
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId("group1");
		$backendGroup->setDisplayName("Group 1");
		$backendGroup->setBackend(get_class($this->backend));
		$this->groupMapper->insert($backendGroup);

		// Group now has new displayname in the backend, so it would need to be changed
		$groupData['displayName'] = 'New Group 1';
		$this->backend->expects($this->at(2))
			->method('getGroupDetails')
			->with('group1')
			->willReturn($groupData);

		// This is new group from backend, it will setup it
		$groupData['displayName'] = 'Group 3';
		$this->backend->expects($this->at(4))
			->method('getGroupDetails')
			->with('group3')
			->willReturn($groupData);

		/**
		 * Check before the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'Group 1');
		$this->assertEquals($backendGroups[0]->getBackend(), get_class($this->backend));

		$groups = [];
		$syncService->run( function ($gid) use (&$groups){
			$groups[] = $gid;
		});

		$this->assertEquals($groups[0], 'group1');
		$this->assertEquals($groups[1], 'group3');
		$this->assertCount(2, $groups);

		/**
		 * Check after the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(2, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'New Group 1');
		$this->assertEquals($backendGroups[0]->getBackend(), get_class($this->backend));
		$this->assertEquals($backendGroups[1]->getGroupId(), 'group3');
		$this->assertEquals($backendGroups[1]->getDisplayName(), 'Group 3');
		$this->assertEquals($backendGroups[1]->getBackend(), get_class($this->backend));
	}

	public function testSyncBackendDuplicate() {
		$syncService = $this->getSyncService();

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue(['group1']));

		$this->backend->expects($this->never())
			->method('implementsActions')
			->willReturn(true);

		// Insert first some existing group into internal mapping
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId("group1");
		$backendGroup->setDisplayName("Group 1");
		$backendGroup->setBackend('Test/Backend');
		$this->groupMapper->insert($backendGroup);

		/**
		 * Check before the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'Group 1');
		$this->assertEquals($backendGroups[0]->getBackend(), 'Test/Backend');

		// This run should detect that group1 exists already in backend Test/Backend and
		// entry for backend $this->backend cannot be processed
		$groups = [];
		$syncService->run( function ($gid) use (&$groups){
			$groups[] = $gid;
		});

		// No groups should be processed since there was skip due to duplicate backend entry
		$this->assertCount(0, $groups);

		/**
		 * Check after the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'Group 1');
		$this->assertEquals($backendGroups[0]->getBackend(), 'Test/Backend');
	}

	public function testSyncNoGroupDetails() {
		$syncService = $this->getSyncService();

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue(['group1','group3']));

		$this->backend->expects($this->any())
			->method('implementsActions')
			->willReturn(false);

		$this->backend->expects($this->never())
			->method('getGroupDetails');

		// Insert first some existing group into internal mapping
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId("group1");
		$backendGroup->setDisplayName("Group 1");
		$backendGroup->setBackend(get_class($this->backend));
		$this->groupMapper->insert($backendGroup);

		/**
		 * Check before the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(1, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'Group 1');

		$groups = [];
		$syncService->run( function ($gid) use (&$groups){
			$groups[] = $gid;
		});

		$this->assertEquals($groups[0], 'group1');
		$this->assertEquals($groups[1], 'group3');
		$this->assertCount(2, $groups);

		/**
		 * Check after the sync internal backend group mapping
		 *
		 * @var BackendGroup[] $backendGroups
		 */
		$backendGroups = array_values($this->groupMapper->search('', 100, 0));
		$this->assertCount(2, $backendGroups);
		$this->assertEquals($backendGroups[0]->getGroupId(), 'group1');
		$this->assertEquals($backendGroups[0]->getDisplayName(), 'group1');
		$this->assertEquals($backendGroups[1]->getGroupId(), 'group3');
		$this->assertEquals($backendGroups[1]->getDisplayName(), 'group3');
	}

	public function testGetNoLongerExistingGroup() {
		$syncService = $this->getSyncService();

		$this->backend->expects($this->at(0))
			->method('getGroups')
			->with('', 500, 0)
			->will($this->returnValue(['group1','group3']));

		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId("group2");
		$backendGroup->setDisplayName("Group 2");
		$backendGroup->setBackend(get_class($this->backend));
		$this->groupMapper->insert($backendGroup);

		$toDeleteGroups = $syncService->getNoLongerExistingGroup( function ($backendGroup){
		});

		$this->assertCount(1, $toDeleteGroups);
		$this->assertEquals($toDeleteGroups[0], 'group2');
	}

	private function getSyncService() {
		$config = $this->createMock(IConfig::class);
		$logger = $this->createMock(ILogger::class);
		return new SyncService($this->groupMapper, $this->backend, $config, $logger);
	}
}