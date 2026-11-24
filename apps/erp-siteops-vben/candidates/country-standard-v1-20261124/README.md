# ERP2 Global Country Standard V1 frontend adapter

The dictionary is copied byte-for-byte from the ERP1 canonical resource at
resources/data/countries/iso3166-alpha2.json. The only component differences
are relative import paths required by the Vben directory layout.
Dictionary SHA256: 14f86bf9f1b83ff2751daa711aa7ff28bce4df5e1cbfd81946a097801ddf3cdc.

Use the component in migrated country input forms:
<CountrySelect v-model="form.country" />
<CountrySelect v-model="form.country_code" />
<CountrySelect v-model="form.shipping_country" />

The present ERP2 Vben workspace exposes order detail read and note append,
but not native order creation/address editing or native KOL country forms.
Do not implement a competing order writer or expose new write routes in this
country-standard branch. When those forms are migrated, reuse these components
and the Laravel backend normalization contract. Do not introduce a second
independently maintained dictionary. Refresh the copied adapter from the
canonical resource when the backend is updated.

Windows source path: D:/ai/erp-siteops/frontend/apps/web-antd/src/components/erp-country-standard.
This adapter adds only standalone components; no current ERP2 page or gateway
is overwritten. ERP2 native-form end-to-end UAT remains pending the form migration.
