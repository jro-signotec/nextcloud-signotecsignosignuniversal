import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "node:path";

export default createAppConfig(
	{
		pdfContextMenu: resolve(join("src", "pdfContextMenu.ts")),
		adminSettings: resolve(join("src", "adminSettings.ts")),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
		assetFileNames: (assetInfo) => {
			if (assetInfo.names.some((n) => n.endsWith('.css'))) {
				return 'css/[name].css'
			}
		},
		config: {
			build: {
				sourcemap: false,
				chunkSizeWarningLimit: 1500,
				rollupOptions: {
					output: {
						chunkFileNames: 'js/[name].chunk.mjs',
					},
				},
			},
		},
	},
);
