/**
 * Philippine Address Selector - PSGC Database Version
 * Fetches address data from AJAX endpoints backed by MySQL PSGC tables
 * 
 * AJAX Endpoints (ajax/address.php):
 *   ?action=regions
 *   ?action=provinces&region_code=XX
 *   ?action=cities&province_code=XXXX
 *   ?action=barangays&municipality_code=XXXXXX
 */

(function() {
    'use strict';

    // Path to AJAX handler (relative to the page calling it)
    const AJAX_PATH = 'ajax/address.php';

    // Simple in-memory cache
    const cache = {};

    async function fetchData(params) {
        const key = new URLSearchParams(params).toString();
        if (cache[key]) return cache[key];

        const url = AJAX_PATH + '?' + key;
        const res = await fetch(url);
        if (!res.ok) throw new Error('Failed to fetch: ' + url);
        const data = await res.json();
        cache[key] = data;
        return data;
    }

    function populate(selectEl, items, valueKey, textKey, placeholder) {
        if (!selectEl) return;
        selectEl.innerHTML = '<option value="">' + placeholder + '</option>';
        (items || []).forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = item[textKey];
            selectEl.appendChild(opt);
        });
    }

    function resetSelect(selectEl, placeholder) {
        if (!selectEl) return;
        selectEl.innerHTML = '<option value="">' + placeholder + '</option>';
    }

    /**
     * PhAddressSelector.init(config)
     *
     * config = {
     *   prefix         : string  (e.g. 'profile_' or 'acc_')
     *   regionId       : string  (select element id)
     *   provinceId     : string
     *   cityId         : string
     *   barangayId     : string
     *   regionTextId   : string  (hidden input id for text value)
     *   provinceTextId : string
     *   cityTextId     : string
     *   barangayTextId : string
     *   defaultRegion  : string  (region_code to preselect)
     *   defaultProvince: string
     *   defaultCity    : string
     *   defaultBarangay: string
     *   onChange       : function(level, value, text)
     * }
     */
    window.PhAddressSelector = {

        init: function(config) {
            config = config || {};
            const p = config.prefix || '';

            const regionSel   = document.getElementById(config.regionId   || p + 'region');
            const provinceSel = document.getElementById(config.provinceId || p + 'province');
            const citySel     = document.getElementById(config.cityId     || p + 'city');
            const barangaySel = document.getElementById(config.barangayId || p + 'barangay');

            const regionText   = document.getElementById(config.regionTextId   || p + 'region_text');
            const provinceText = document.getElementById(config.provinceTextId || p + 'province_text');
            const cityText     = document.getElementById(config.cityTextId     || p + 'city_text');
            const barangayText = document.getElementById(config.barangayTextId || p + 'barangay_text');

            if (!regionSel) {
                console.warn('PhAddressSelector: Region select element not found');
                return;
            }

            function loadBarangays(municipalityCode, defaultBarangay) {
                fetchData({ action: 'barangays', municipality_code: municipalityCode })
                    .then(function(data) {
                        populate(barangaySel, data, 'barangay_code', 'barangay_name', 'Select Barangay');
                        if (defaultBarangay) {
                            barangaySel.value = defaultBarangay;
                            const opt = barangaySel.options[barangaySel.selectedIndex];
                            if (barangayText && opt && opt.value) barangayText.value = opt.text;
                        }
                    }).catch(function(err) {
                        console.error('PhAddressSelector: Failed to load barangays', err);
                    });
            }

            function loadCities(provinceCode, defaultCity) {
                fetchData({ action: 'cities', province_code: provinceCode })
                    .then(function(data) {
                        populate(citySel, data, 'municipality_code', 'municipality_name', 'Select City/Municipality');
                        if (defaultCity) {
                            citySel.value = defaultCity;
                            const opt = citySel.options[citySel.selectedIndex];
                            if (cityText && opt && opt.value) cityText.value = opt.text;
                            loadBarangays(defaultCity, config.defaultBarangay);
                        }
                    }).catch(function(err) {
                        console.error('PhAddressSelector: Failed to load cities', err);
                    });
            }

            function loadProvinces(regionCode, defaultProvince) {
                fetchData({ action: 'provinces', region_code: regionCode })
                    .then(function(data) {
                        populate(provinceSel, data, 'province_code', 'province_name', 'Select Province');
                        if (defaultProvince) {
                            provinceSel.value = defaultProvince;
                            const opt = provinceSel.options[provinceSel.selectedIndex];
                            if (provinceText && opt && opt.value) provinceText.value = opt.text;
                            loadCities(defaultProvince, config.defaultCity);
                        }
                    }).catch(function(err) {
                        console.error('PhAddressSelector: Failed to load provinces', err);
                    });
            }

            // Load Regions on init
            fetchData({ action: 'regions' })
                .then(function(data) {
                    populate(regionSel, data, 'region_code', 'region_name', 'Select Region');
                    if (config.defaultRegion) {
                        regionSel.value = config.defaultRegion;
                        const opt = regionSel.options[regionSel.selectedIndex];
                        if (regionText && opt && opt.value) regionText.value = opt.text;
                        loadProvinces(config.defaultRegion, config.defaultProvince);
                    }
                }).catch(function(err) {
                    console.error('PhAddressSelector: Failed to load regions', err);
                });

            // Region change
            regionSel.addEventListener('change', function() {
                const code = this.value;
                const text = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
                if (regionText) regionText.value = code ? text : '';
                resetSelect(provinceSel, 'Select Province');
                resetSelect(citySel, 'Select City/Municipality');
                resetSelect(barangaySel, 'Select Barangay');
                if (provinceText) provinceText.value = '';
                if (cityText)     cityText.value = '';
                if (barangayText) barangayText.value = '';
                if (code) loadProvinces(code, null);
                if (config.onChange) config.onChange('region', code, text);
            });

            // Province change
            if (provinceSel) {
                provinceSel.addEventListener('change', function() {
                    const code = this.value;
                    const text = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
                    if (provinceText) provinceText.value = code ? text : '';
                    resetSelect(citySel, 'Select City/Municipality');
                    resetSelect(barangaySel, 'Select Barangay');
                    if (cityText)     cityText.value = '';
                    if (barangayText) barangayText.value = '';
                    if (code) loadCities(code, null);
                    if (config.onChange) config.onChange('province', code, text);
                });
            }

            // City change
            if (citySel) {
                citySel.addEventListener('change', function() {
                    const code = this.value;
                    const text = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
                    if (cityText) cityText.value = code ? text : '';
                    resetSelect(barangaySel, 'Select Barangay');
                    if (barangayText) barangayText.value = '';
                    if (code) loadBarangays(code, null);
                    if (config.onChange) config.onChange('city', code, text);
                });
            }

            // Barangay change
            if (barangaySel) {
                barangaySel.addEventListener('change', function() {
                    const code = this.value;
                    const text = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
                    if (barangayText) barangayText.value = code ? text : '';
                    if (config.onChange) config.onChange('barangay', code, text);
                });
            }
        },

        clearCache: function() {
            Object.keys(cache).forEach(function(k) { delete cache[k]; });
        }
    };

})();
