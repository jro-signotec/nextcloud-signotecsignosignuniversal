import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

export default createAppConfig(
	{
		pdfContextMenu: resolve(join("src", "pdfContextMenu.ts")),
		adminSettings: resolve(join("src", "adminSettings.ts")),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
);
