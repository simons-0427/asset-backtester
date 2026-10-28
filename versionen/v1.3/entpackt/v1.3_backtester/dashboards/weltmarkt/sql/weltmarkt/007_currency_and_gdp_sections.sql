-- Upgrade existing Weltmarkt databases with the GDP split and monetary currencies.

START TRANSACTION;

UPDATE `weltmarkt_metrics` SET `section` = 'gdp_nominal' WHERE `metric_id` IN ('us_nom_gdp', 'cn_nom_gdp', 'jp_nom_gdp', 'gr_nom_gdp', 'ez_nom_gdp');

UPDATE `weltmarkt_metrics` SET `section` = 'gdp_growth' WHERE `metric_id` IN ('us_gdp_yoy', 'cn_gdp_yoy', 'jp_gdp_yoy', 'gr_gdp_yoy', 'ez_gdp_yoy', 'world_gdp_yoy');

UPDATE `weltmarkt_metrics` SET `raw_currency` = CASE `metric_id` WHEN 'fed_bs' THEN 'USD' WHEN 'ecb_bs' THEN 'EUR' WHEN 'boj_bs' THEN 'JPY' ELSE `raw_currency` END WHERE `metric_id` IN ('fed_bs', 'ecb_bs', 'boj_bs');

COMMIT;
