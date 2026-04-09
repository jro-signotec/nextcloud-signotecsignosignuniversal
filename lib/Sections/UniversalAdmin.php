<?php

namespace OCA\SignotecSignoSignUniversal\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class UniversalAdmin implements IIconSection {
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID() {
		return 'signotecsignosignuniversal';
	}

	public function getName() {
		return $this->l->t('signoSignUniversal Settings');
	}


	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/settings-dark.svg');
	}

	public function getPriority(): int {
		return 98;
	}
}
