<?php

namespace OCA\SignotecSignoSignUniversal\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class UniversalAdmin implements IIconSection {
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	#[\Override]
	public function getID() {
		return 'signotecsignosignuniversal';
	}

	#[\Override]
	public function getName() {
		return $this->l->t('signoSignUniversal Settings');
	}


	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/settings-dark.svg');
	}

	#[\Override]
	public function getPriority(): int {
		return 98;
	}
}
