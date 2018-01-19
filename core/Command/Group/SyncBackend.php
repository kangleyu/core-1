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

namespace OC\Core\Command\Group;

use OC\Group\GroupMapper;
use OC\Group\SyncService;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class SyncBackend extends Command {

	/** @var GroupMapper */
	protected $groupMapper;
	/** @var IConfig */
	private $config;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IUserManager */
	private $userManager;
	/** @var ILogger */
	private $logger;

	/**
	 * @param GroupMapper $groupMapper
	 * @param IConfig $config
	 * @param IGroupManager $groupManager
	 * @param ILogger $logger
	 */
	public function __construct(GroupMapper $groupMapper,
								IConfig $config,
								ILogger $logger,
								IGroupManager $groupManager,
								IUserManager $userManager) {
		parent::__construct();
		$this->groupMapper = $groupMapper;
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	protected function configure() {
		$this
			->setName('group:sync')
			->setDescription('Synchronize groups from a given backend to the backend groups table.')
			->addArgument(
				'backend-class',
				InputArgument::OPTIONAL,
				'The PHP class name - e.g., "OCA\User_LDAP\Group_Proxy". Please wrap the class name in double quotes. You can use the option --list to list all known backend classes.'
			)
			->addOption(
				'list',
				'l',
				InputOption::VALUE_NONE,
				'List all known backend classes'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($input->getOption('list')) {
			$backends = $this->groupManager->getBackends();
			foreach ($backends as $backend) {
				$output->writeln(get_class($backend));
			}
			return 0;
		}
		$backendClassName = $input->getArgument('backend-class');
		if (is_null($backendClassName)) {
			$output->writeln("<error>No backend class name given. Please run ./occ help group:sync to understand how this command works.</error>");
			return 1;
		}
		$backend = $this->getBackend($backendClassName);
		if (is_null($backend)) {
			$output->writeln("<error>The backend <$backendClassName> does not exist. Did you miss to enable the app?</error>");
			return 1;
		}

		$syncService = new SyncService($this->groupMapper, $backend, $this->config, $this->logger);

		// Count groups
		$backendGroupsNo = $this->handleCount($output, $syncService);

		// Analyse unknown groups
		$this->handleUnknownGroups($output, $syncService);

		// Sync groups
		$this->handleGroupUpdate($output, $syncService, $backendGroupsNo);

		// Sync memberships
		$this->handleMembershipsUpdate($output, $syncService, $backendGroupsNo);

		return 0;
	}

	/**
	 * @param $backend
	 * @return null|GroupInterface
	 */
	private function getBackend($backend) {
		$backends = $this->groupManager->getBackends();
		$match = array_filter($backends, function ($b) use ($backend) {
			return get_class($b) === $backend;
		});
		if (empty($match)) {
			return null;
		}
		return array_pop($match);
	}

	/**
	 * @param array $gids a list of $gid id for the the action
	 * @param callable $callbackExists the callback used if the backend group for the gid exists. The
	 * gid and the specific backend group will be passed as parameter to the callback in that order
	 * @param callable $callbackMissing the callback used if the backend group doesn't exists. The gid (not
	 * the backend group) will be passed as parameter to the callback
	 */
	private function doActionForGIDs(array $gids, callable $callbackExists, callable $callbackMissing = null) {
		foreach ($gids as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				$callbackMissing($gid);
			} else {
				$callbackExists($gid, $group);
			}
		}
	}

	/**
	 * @param OutputInterface $output
	 * @param SyncService $syncService
	 *
	 * @return int - number of groups in external backend
	 */
	private function handleCount(OutputInterface $output, SyncService $syncService) {
		$output->writeln("Count groups from external backend ...");
		$p = new ProgressBar($output);
		$max = 0;
		$syncService->count(function () use ($p, &$max) {
			$p->advance();
			$max++;
		});
		$p->finish();
		$output->writeln('');
		$output->writeln('');

		return $max;
	}

	/**
	 * @param OutputInterface $output
	 * @param SyncService $syncService
	 */
	private function handleUnknownGroups(OutputInterface $output, SyncService $syncService) {
		$output->writeln("Scan existing groups and find groups to delete...");
		$p = new ProgressBar($output);
		$toBeDeleted = $syncService->getNoLongerExistingGroup(function () use ($p) {
			$p->advance();
		});
		$p->finish();
		$output->writeln('');
		$output->writeln('');

		if (empty($toBeDeleted)) {
			$output->writeln("No groups to be deleted have been detected.");
		} else {
			$output->writeln("Proceeding to remove the backend groups. Following groups are no longer known with the connected backend.");
			$output->writeln('');

			$this->doActionForGIDs($toBeDeleted,
				function ($gid, IGroup $group) use ($output) {
					$group->delete();
					$output->writeln($gid);
				},
				function ($gid) use ($output) {
					$output->writeln($gid . " (unknown backend group)");
				}
			);
		}
		$output->writeln('');
		$output->writeln('');
	}

	/**
	 * @param OutputInterface $output
	 * @param SyncService $syncService
	 * @param int $backendGroupsNo
	 */
	private function handleGroupUpdate(OutputInterface $output, SyncService $syncService, $backendGroupsNo) {
		// insert/update known users
		$output->writeln("Insert new and update existing groups ...");
		$p = new ProgressBar($output);
		$p->start($backendGroupsNo);
		$syncService->run(function () use ($p) {
			$p->advance();
		});
		$p->finish();
		$output->writeln('');
		$output->writeln('');
	}

	/**
	 * @param OutputInterface $output
	 * @param SyncService $syncService
	 * @param int $backendGroupsNo
	 */
	private function handleMembershipsUpdate(OutputInterface $output, SyncService $syncService, $backendGroupsNo) {
		// insert/update known users
		$output->writeln("Fetch remote users for fetched and synced groups ...");
		$p = new ProgressBar($output);
		$p->start($backendGroupsNo);

		// Fetch all groups and their corresponding users from remote backend e.g. LDAP
		$remoteGidUidsMap = $syncService->getRemoteGroupUsers(function () use ($p) {
			$p->advance();
		});

		$p->finish();
		$output->writeln('');
		$output->writeln('');
		$output->writeln("Sync memberships for synced groups ...");
		$p = new ProgressBar($output);
		$p->start($backendGroupsNo);

		// For each remote group and corresponding users, sync with existing users and memberships
		foreach ($remoteGidUidsMap as $gid => $remoteGroupUserIds) {
			$group = $this->groupManager->get($gid);
			// For all local synced users from several backends for specific group with group id $gid,
			// map to backendclass -> userid map
			$localBackendUserIdsMap = $this->getBackendToUidMapForExistingGroupUsers($group);

			// For all remote backend users e.g. LDAP for specific group with group id $gid,
			// check if they have synced account already, and map
			// to backendclass -> userid map
			$remoteBackendUserIdsMap = $this->getBackendToUidMapForRemoteUsers($remoteGroupUserIds);

			// For each remote backend e.g. LDAP and existing users there for this group identified by gid
			// sync with local group users
			$membershipsToRemove = [];
			$membershipsToAdd = [];
			foreach ($this->userManager->getBackends() as $userBackend) {
				$userBackendClass = get_class($userBackend);

				$localUids = [];
				if (array_key_exists($userBackendClass, $localBackendUserIdsMap)) {
					$localUids = $localBackendUserIdsMap[$userBackendClass];
				}

				$remoteUids = [];
				if (array_key_exists($userBackendClass, $remoteBackendUserIdsMap)) {
					$remoteUids = $remoteBackendUserIdsMap[$userBackendClass];
				}

//				// Check which memberships need to removed and added for this backend class
				$membershipCandidatesToRemove = array_diff($localUids, $remoteUids);
				foreach ($membershipCandidatesToRemove as $uid) {

				}
				$membershipCandidatesToAdd = array_diff($remoteUids, $localUids);


			}

			foreach ($membershipsToRemove as $uid) {
				$user = $this->userManager->get($uid);
				$group->removeUser($user);
			}

			foreach ($membershipsToAdd as $uid) {
				$user = $this->userManager->get($uid);
				$group->addUser($user);
			}
		}

		$p->finish();
		$output->writeln('');
		$output->writeln('');
	}

	/**
	 * Map to backendclass -> userid map for
	 * all local synced users from several backends for specific group,
	 *
	 * @param IGroup $group
	 * @return array - backendclass (string) -> uids (string[]) map
	 */
	private function getBackendToUidMapForExistingGroupUsers($group){
		$localBackendUserIdsMap = [];
		foreach ($group->getUsers() as $localUser) {
			$localBackendUserIdsMap[$localUser->getBackendClassName()][] = $localUser->getUID();
		}

		return $localBackendUserIdsMap;
	}

	/**
	 * Map to backendclass -> userid map for
	 * all remote backend users e.g. LDAP, check if they have synced account already
	 *
	 * @param string[] $remoteUserIds
	 * @return array - backendclass (string) -> uids (string[]) map
	 */
	private function getBackendToUidMapForRemoteUsers($remoteUserIds){
		$remoteBackendUserIdsMap = [];
		foreach ($remoteUserIds as $remoteUid) {
			if ($this->userManager->userExists($remoteUid)) {
				$user = $this->userManager->get($remoteUid);
				$remoteBackendUserIdsMap[$user->getBackendClassName()][] = $user->getUID();
			}
		}

		return $remoteBackendUserIdsMap;
	}
}
