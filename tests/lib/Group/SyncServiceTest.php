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
use OC\Group\GroupMapper;
use OC\Group\SyncService;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\ILogger;
use Test\TestCase;

class SyncServiceTest extends TestCase {

	public function testSetupAccount() {
		$mapper = $this->createMock(GroupMapper::class);
		$backend = $this->getMockBuilder(GroupInterface::class)
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
		$config = $this->createMock(IConfig::class);
		$logger = $this->createMock(ILogger::class);

		$groupDetails = [];
		$groupDetails['gid'] = 'group1';
		$groupDetails['displayName'] = 'Group 1';
		$backend->expects($this->once())
			->method('getGroupDetails')
			->with($groupDetails['gid'])
			->will($this->returnValue($groupDetails));
		$backend->expects($this->once())
			->method('implementsActions')
			->will($this->returnValue(true));

		$s = new SyncService($mapper, $backend, $config, $logger);
		$b = new BackendGroup();
		$s->setupBackendGroup($b, $groupDetails['gid']);

		$this->assertEquals('Group 1', $b->getDisplayName());
	}
}