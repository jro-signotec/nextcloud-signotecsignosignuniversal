<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'signotecsignosignuniversal';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		Util::addScript(self::APP_ID, self::APP_ID . '-pdfContextMenu', 'files');
		Util::addStyle(self::APP_ID, self::APP_ID . '-pdfContextMenu');
	}

	// Imported by the OCP\AppFramework\Bootstrap\IBootstrap interface this method is called during the boot process of the application.
	// It can be used to perform any necessary initialization or setup tasks when the application is being loaded.
	// In this implementation, the method is currently empty, indicating that no specific boot actions are required for this application at this time.
	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
