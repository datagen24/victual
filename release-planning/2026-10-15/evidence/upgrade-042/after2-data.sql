--
-- PostgreSQL database dump
--

\restrict hzUaqGhaEmftl5JVhy3Cc63UL9M794Iolsbt1tch24HgtDRTAiyRoVrOGre55vC

-- Dumped from database version 16.15 (Debian 16.15-1.pgdg13+2)
-- Dumped by pg_dump version 16.15 (Debian 16.15-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: api_keys; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: batteries; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: battery_charge_cycles; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: cache__products_average_price; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.cache__products_average_price (id, product_id, price) VALUES (1, 1, 2.5);


--
-- Data for Name: cache__products_last_purchased; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.cache__products_last_purchased (id, product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id) VALUES (1, 1, 3, '2027-01-31', '2026-10-15', 2.5, 2, NULL);


--
-- Data for Name: cache__quantity_unit_conversions_resolved; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.cache__quantity_unit_conversions_resolved (id, product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path) VALUES (1, 1, 2, 'Piece', 'Pieces', 2, 'Piece', 'Pieces', '1', '/2/2/');


--
-- Data for Name: chores; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.chores (id, name, description, period_type, period_days, row_created_timestamp, period_config, track_date_only, rollover, assignment_type, assignment_config, next_execution_assigned_to_user_id, consume_product_on_execution, product_id, product_amount, period_interval, active, start_date, rescheduled_date, rescheduled_next_execution_assigned_to_user_id, import_epoch) VALUES (1, 'Upgrade audit chore', NULL, 'manually', NULL, '2026-10-15 16:11:54+00', NULL, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, 1, 1, '2026-10-15 16:11:54+00', NULL, NULL, 0);


--
-- Data for Name: chores_log; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.chores_log (id, chore_id, tracked_time, done_by_user_id, row_created_timestamp, undone, undone_timestamp, skipped, scheduled_execution_time, stock_transaction_id, prior_rescheduled_date, prior_rescheduled_next_execution_assigned_to_user_id) VALUES (1, 1, '2026-01-01 12:00:00+00', 1, '2026-10-15 16:11:54+00', 0, NULL, 0, NULL, NULL, NULL, NULL);
INSERT INTO public.chores_log (id, chore_id, tracked_time, done_by_user_id, row_created_timestamp, undone, undone_timestamp, skipped, scheduled_execution_time, stock_transaction_id, prior_rescheduled_date, prior_rescheduled_next_execution_assigned_to_user_id) VALUES (2, 1, '2026-01-02 12:00:00+00', 1, '2026-10-15 16:11:54+00', 0, NULL, 0, NULL, NULL, NULL, NULL);
INSERT INTO public.chores_log (id, chore_id, tracked_time, done_by_user_id, row_created_timestamp, undone, undone_timestamp, skipped, scheduled_execution_time, stock_transaction_id, prior_rescheduled_date, prior_rescheduled_next_execution_assigned_to_user_id) VALUES (3, 1, '2026-01-03 13:00:00+00', 1, '2026-10-15 16:11:54+00', 0, NULL, 0, NULL, NULL, NULL, NULL);


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.users (id, username, first_name, last_name, password, row_created_timestamp, picture_file_name, must_change_password) VALUES (1, 'admin', NULL, NULL, '$argon2id$v=19$m=65536,t=4,p=1$YzZMOGVaUUExbVFaakFBZQ$e/6yTo58xuend8fb/mVUtfSwwC7pOfyWEKn2c/8fcEw', '2026-10-15 16:11:23+00', NULL, 0);


--
-- Data for Name: consumption_recipes; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: quantity_units; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.quantity_units (id, name, description, row_created_timestamp, name_plural, plural_forms, active) VALUES (2, 'Piece', NULL, '2026-10-15 16:11:23+00', 'Pieces', NULL, 1);
INSERT INTO public.quantity_units (id, name, description, row_created_timestamp, name_plural, plural_forms, active) VALUES (3, 'Pack', NULL, '2026-10-15 16:11:23+00', 'Packs', NULL, 1);


--
-- Data for Name: storage_classes; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.storage_classes (id, name, min_temp_c, max_temp_c, treats_as_freezer, sort_order, row_created_timestamp, active) VALUES (1, 'Deep freeze', -40, -18, 1, 0, '2026-10-15 16:11:24+00', 1);
INSERT INTO public.storage_classes (id, name, min_temp_c, max_temp_c, treats_as_freezer, sort_order, row_created_timestamp, active) VALUES (2, 'Freezer', -18, -12, 1, 1, '2026-10-15 16:11:24+00', 1);
INSERT INTO public.storage_classes (id, name, min_temp_c, max_temp_c, treats_as_freezer, sort_order, row_created_timestamp, active) VALUES (3, 'Fridge', 2, 8, 0, 2, '2026-10-15 16:11:24+00', 1);
INSERT INTO public.storage_classes (id, name, min_temp_c, max_temp_c, treats_as_freezer, sort_order, row_created_timestamp, active) VALUES (4, 'Cooler', 8, 15, 0, 3, '2026-10-15 16:11:24+00', 1);
INSERT INTO public.storage_classes (id, name, min_temp_c, max_temp_c, treats_as_freezer, sort_order, row_created_timestamp, active) VALUES (5, 'Ambient', 15, 25, 0, 4, '2026-10-15 16:11:24+00', 1);


--
-- Data for Name: locations; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.locations (id, name, description, row_created_timestamp, is_freezer, active, import_epoch, parent_location_id, storage_class_id, tare_weight, tare_qu_id) VALUES (2, 'Fridge', NULL, '2026-10-15 16:11:23+00', 0, 1, 0, NULL, NULL, NULL, NULL);


--
-- Data for Name: product_groups; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.products (id, name, description, product_group_id, active, location_id, shopping_location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, default_best_before_days_after_open, default_best_before_days_after_freezing, default_best_before_days_after_thawing, picture_file_name, enable_tare_weight_handling, tare_weight, not_check_stock_fulfillment_for_recipes, parent_product_id, calories, cumulate_min_stock_amount_of_sub_products, due_type, quick_consume_amount, hide_on_stock_overview, default_stock_label_type, should_not_be_frozen, treat_opened_as_out_of_stock, no_own_stock, default_consume_location_id, move_on_open, row_created_timestamp, qu_id_consume, auto_reprint_stock_label, quick_open_amount, qu_id_price, disable_open, default_purchase_price_type, quick_refill_amount, default_refill_location_id_from, default_refill_location_id_to, import_epoch, default_shopping_list_id) VALUES (1, 'Upgrade audit oats', NULL, NULL, 1, 2, NULL, 2, 2, 0, 0, 0, 0, 0, NULL, 0, 0, 0, NULL, NULL, 0, 1, 1, 0, 0, 0, 1, 0, NULL, 0, '2026-10-15 16:11:54+00', 2, 0, 1, 2, 0, 1, 1, NULL, NULL, 0, NULL);


--
-- Data for Name: consumption_mappings; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_events; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: stock_log; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.stock_log (id, product_id, amount, best_before_date, purchased_date, used_date, spoiled, stock_id, transaction_type, price, undone, undone_timestamp, opened_date, location_id, recipe_id, correlation_id, transaction_id, stock_row_id, shopping_location_id, user_id, row_created_timestamp, note, opened_amount, opened_qu_id, opened_tare, opened_measured_at) VALUES (1, 1, 3, '2027-01-31', '2026-10-15', NULL, 0, '6ad0fb4ac2644', 'purchase', 2.5, 0, NULL, NULL, 2, NULL, NULL, '6ad0fb4ac255a', NULL, NULL, 1, '2026-10-15 16:11:54+00', NULL, NULL, NULL, NULL, NULL);


--
-- Data for Name: consumption_event_lines; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_recipe_lines; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_recipe_shares; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_refill_acks; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_refill_fills; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_refill_dates; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_refill_orders; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: consumption_refill_settings; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: equipment; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: files; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_captures; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_workers; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_drivers; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_media_profiles; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_templates; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.label_templates (id, name, description, entity_kind, default_version_id, archived_at, row_created_timestamp) VALUES (1, 'Default product label', 'Seeded default label for product (migration 0283)', 'product', 1, NULL, '2026-10-15 16:11:24.086414+00');
INSERT INTO public.label_templates (id, name, description, entity_kind, default_version_id, archived_at, row_created_timestamp) VALUES (2, 'Default stock entry label', 'Seeded default label for stock_entry (migration 0283)', 'stock_entry', 2, NULL, '2026-10-15 16:11:24.086414+00');
INSERT INTO public.label_templates (id, name, description, entity_kind, default_version_id, archived_at, row_created_timestamp) VALUES (3, 'Default recipe label', 'Seeded default label for recipe (migration 0283)', 'recipe', 3, NULL, '2026-10-15 16:11:24.086414+00');
INSERT INTO public.label_templates (id, name, description, entity_kind, default_version_id, archived_at, row_created_timestamp) VALUES (4, 'Default chore label', 'Seeded default label for chore (migration 0283)', 'chore', 4, NULL, '2026-10-15 16:11:24.086414+00');
INSERT INTO public.label_templates (id, name, description, entity_kind, default_version_id, archived_at, row_created_timestamp) VALUES (5, 'Default battery label', 'Seeded default label for battery (migration 0283)', 'battery', 5, NULL, '2026-10-15 16:11:24.086414+00');


--
-- Data for Name: label_template_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.label_template_versions (id, template_id, version, document, document_digest, asset_ids, required_capabilities, published_at, published_by_user_id) VALUES (1, 1, 1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "product", "schema_version": 1}', '225b5d39b1debbc890d99f9d82fd8609f246b0f694bb412a8938aedb89c77e7f', '[]', '{"color_mode": "monochrome"}', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_versions (id, template_id, version, document, document_digest, asset_ids, required_capabilities, published_at, published_by_user_id) VALUES (2, 2, 1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "stock_entry", "schema_version": 1}', 'deb626db5143f21d5e8b48aa854c2e4f8f7c3e8d550f20515918094443325351', '[]', '{"color_mode": "monochrome"}', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_versions (id, template_id, version, document, document_digest, asset_ids, required_capabilities, published_at, published_by_user_id) VALUES (3, 3, 1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "recipe", "schema_version": 1}', 'f53aaee360e548c3ed357537a757818d08830e79a0be724f76194ad543af0f13', '[]', '{"color_mode": "monochrome"}', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_versions (id, template_id, version, document, document_digest, asset_ids, required_capabilities, published_at, published_by_user_id) VALUES (4, 4, 1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "chore", "schema_version": 1}', 'c65c25bc808d4e112e9902f56785e28fa343fc75d538ddbba3609ad95bb34892', '[]', '{"color_mode": "monochrome"}', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_versions (id, template_id, version, document, document_digest, asset_ids, required_capabilities, published_at, published_by_user_id) VALUES (5, 5, 1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "battery", "schema_version": 1}', 'a15ca463311ed6b99ad0e8073b15b6f854f21b7773bb4f9d6b8d7fa5d4b40677', '[]', '{"color_mode": "monochrome"}', '2026-10-15 16:11:24.086414+00', NULL);


--
-- Data for Name: label_render_requests; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_artifacts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_assets; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_idempotency_keys; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_import_state; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.label_import_state (id, epoch) VALUES (1, 0);


--
-- Data for Name: label_printers; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_printer_status; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_template_drafts; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.label_template_drafts (template_id, document, revision_token, updated_at, updated_by_user_id) VALUES (1, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "product", "schema_version": 1}', '2329214aebb194cc8bc6b33bccf2a0e7', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_drafts (template_id, document, revision_token, updated_at, updated_by_user_id) VALUES (2, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "stock_entry", "schema_version": 1}', 'a0fa15db83312d63d2ec6b66718cf467', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_drafts (template_id, document, revision_token, updated_at, updated_by_user_id) VALUES (3, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "recipe", "schema_version": 1}', 'a00cd1a59cae4383297b66dcee02076e', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_drafts (template_id, document, revision_token, updated_at, updated_by_user_id) VALUES (4, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "chore", "schema_version": 1}', 'd2204162ff051e955adc9f4d9d5fdb15', '2026-10-15 16:11:24.086414+00', NULL);
INSERT INTO public.label_template_drafts (template_id, document, revision_token, updated_at, updated_by_user_id) VALUES (5, '{"canvas": {"width_mm": 58.9, "height_mm": null, "margins_mm": {"top": 2, "left": 2, "right": 2, "bottom": 2}, "max_height_mm": 100}, "elements": [{"id": "code", "type": "qr", "x_mm": 2, "y_mm": 2, "color": "black", "source": "label.payload", "ec_level": "M", "module_mm": 0.6, "quiet_zone_modules": 4}], "entity_kind": "battery", "schema_version": 1}', '79e84006eaa5baa3517bc473baad0efa', '2026-10-15 16:11:24.086414+00', NULL);


--
-- Data for Name: label_worker_capabilities; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_worker_sessions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: label_worker_credentials; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: labels; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: login_attempts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: meal_plan; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: meal_plan_sections; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.meal_plan_sections (id, name, sort_number, row_created_timestamp, time_info) VALUES (-1, '', -1, '2026-10-15 16:11:23+00', NULL);


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (1, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (2, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (3, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (4, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (5, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (6, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (7, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (8, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (9, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (10, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (11, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (12, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (13, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (14, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (15, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (16, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (17, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (18, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (19, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (20, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (21, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (22, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (23, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (24, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (25, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (26, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (27, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (28, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (29, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (30, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (31, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (32, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (33, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (34, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (35, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (36, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (37, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (38, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (39, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (40, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (41, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (42, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (43, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (44, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (45, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (46, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (47, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (48, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (49, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (50, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (51, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (52, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (53, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (54, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (55, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (56, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (57, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (58, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (59, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (60, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (61, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (62, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (63, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (64, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (65, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (66, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (67, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (68, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (69, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (70, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (71, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (72, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (73, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (74, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (75, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (76, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (77, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (78, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (79, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (80, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (81, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (82, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (83, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (84, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (85, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (86, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (87, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (88, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (89, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (90, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (91, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (92, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (93, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (94, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (95, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (96, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (97, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (98, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (99, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (100, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (101, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (102, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (103, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (104, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (105, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (106, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (107, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (108, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (109, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (110, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (111, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (112, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (113, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (114, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (115, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (116, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (117, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (118, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (119, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (120, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (121, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (122, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (123, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (124, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (125, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (126, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (127, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (128, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (129, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (130, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (131, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (132, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (133, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (134, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (135, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (136, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (137, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (138, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (139, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (140, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (141, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (142, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (143, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (144, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (145, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (146, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (147, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (148, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (149, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (150, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (151, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (152, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (153, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (154, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (155, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (156, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (157, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (158, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (159, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (160, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (161, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (162, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (163, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (164, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (165, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (166, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (167, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (168, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (169, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (170, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (171, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (172, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (173, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (174, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (175, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (176, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (177, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (178, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (179, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (180, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (181, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (182, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (183, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (184, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (185, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (186, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (187, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (188, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (189, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (190, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (191, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (192, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (193, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (194, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (195, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (196, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (197, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (198, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (199, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (200, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (201, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (202, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (203, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (204, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (205, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (206, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (207, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (208, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (209, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (210, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (211, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (212, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (213, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (214, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (215, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (216, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (217, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (218, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (219, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (220, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (221, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (222, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (223, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (224, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (225, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (226, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (227, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (228, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (229, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (230, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (231, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (232, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (233, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (234, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (235, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (236, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (237, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (238, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (239, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (240, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (241, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (242, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (243, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (244, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (245, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (246, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (247, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (248, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (249, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (250, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (251, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (252, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (253, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (254, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (255, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (257, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (258, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (259, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (260, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (261, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (262, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (263, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (264, '2026-10-15 16:11:23+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (265, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (266, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (267, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (268, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (269, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (270, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (271, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (272, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (273, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (274, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (275, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (276, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (277, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (278, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (279, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (280, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (281, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (282, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (283, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (284, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (285, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (286, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (287, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (288, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (289, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (290, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (291, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (292, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (293, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (294, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (295, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (296, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (297, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (298, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (299, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (300, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (301, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (302, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (303, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (304, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (305, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (306, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (307, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (308, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (309, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (310, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (311, '2026-10-15 16:11:24+00');
INSERT INTO public.migrations (migration, execution_time_timestamp) VALUES (312, '2026-10-15 16:11:24+00');


--
-- Data for Name: mqtt_product_entities; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: mqtt_published_entities; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: outbox; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: permission_hierarchy; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (1, 'ADMIN', NULL);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (2, 'USERS', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (3, 'USERS_CREATE', 2);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (4, 'USERS_EDIT', 3);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (5, 'USERS_READ', 4);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (6, 'USERS_EDIT_SELF', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (7, 'STOCK', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (8, 'SHOPPINGLIST', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (9, 'RECIPES', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (10, 'CHORES', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (11, 'BATTERIES', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (12, 'TASKS', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (13, 'EQUIPMENT', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (14, 'CALENDAR', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (15, 'STOCK_PURCHASE', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (16, 'STOCK_CONSUME', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (17, 'STOCK_INVENTORY', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (18, 'STOCK_TRANSFER', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (19, 'STOCK_OPEN', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (20, 'STOCK_EDIT', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (21, 'SHOPPINGLIST_ITEMS_ADD', 8);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (22, 'SHOPPINGLIST_ITEMS_DELETE', 8);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (23, 'RECIPES_MEALPLAN', 9);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (24, 'CHORE_TRACK_EXECUTION', 10);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (25, 'CHORE_UNDO_EXECUTION', 10);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (26, 'BATTERIES_TRACK_CHARGE_CYCLE', 11);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (27, 'BATTERIES_UNDO_CHARGE_CYCLE', 11);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (28, 'TASKS_UNDO_EXECUTION', 12);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (29, 'TASKS_MARK_COMPLETED', 12);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (30, 'MASTER_DATA_EDIT', 1);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (31, 'STOCK_VIEW', 7);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (32, 'SHOPPINGLIST_VIEW', 8);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (33, 'CHORES_VIEW', 10);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (34, 'TASKS_VIEW', 12);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (35, 'RECIPES_VIEW', 9);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (36, 'MEALPLAN_VIEW', 23);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (37, 'STOCK_PRICES_VIEW', 15);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (38, 'BATTERIES_VIEW', 11);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (39, 'CALENDAR_VIEW', 14);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (40, 'EQUIPMENT_VIEW', 13);
INSERT INTO public.permission_hierarchy (id, name, parent) VALUES (41, 'MASTER_DATA_ADD', 30);


--
-- Data for Name: permission_fields; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (1, 'STOCK_PRICES_VIEW', 'stock', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (2, 'STOCK_PRICES_VIEW', 'stock_log', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (3, 'STOCK_PRICES_VIEW', 'stock_current', 'value');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (4, 'STOCK_PRICES_VIEW', 'stock_next_use', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (5, 'STOCK_PRICES_VIEW', 'products_average_price', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (6, 'STOCK_PRICES_VIEW', 'products_last_purchased', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (7, 'STOCK_PRICES_VIEW', 'recipes_pos_resolved', 'costs');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (8, 'STOCK_PRICES_VIEW', 'recipes_resolved', 'costs');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (9, 'STOCK_PRICES_VIEW', 'recipes_resolved', 'costs_per_serving');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (10, 'STOCK_PRICES_VIEW', 'recipes_resolved', 'prices_incomplete');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (11, 'STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'value');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (12, 'STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'last_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (13, 'STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'average_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (14, 'STOCK_PRICES_VIEW', 'products_price_history', '*');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (15, 'STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'last_price_unit');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (16, 'STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'last_price_total');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (17, 'STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (18, 'STOCK_PRICES_VIEW', 'product_details', 'last_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (19, 'STOCK_PRICES_VIEW', 'product_details', 'avg_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (20, 'STOCK_PRICES_VIEW', 'product_details', 'oldest_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (21, 'STOCK_PRICES_VIEW', 'product_details', 'current_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (22, 'STOCK_PRICES_VIEW', 'product_details', 'stock_value');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (29, 'STOCK_PRICES_VIEW', 'product_barcodes', 'last_price');
INSERT INTO public.permission_fields (id, permission_name, entity, field) VALUES (30, 'STOCK_PRICES_VIEW', 'product_barcodes_view', 'last_price');


--
-- Data for Name: print_jobs; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: print_attempts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: print_evidence; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: product_barcodes; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: product_location_min_stock; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: product_substitutions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: quantity_unit_conversions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: recipes; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: recipes_nestings; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: recipes_pos; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.roles (id, code, name, description, builtin, row_created_timestamp) VALUES (1, 'ADMIN', 'Admin', NULL, 1, '2026-10-15 16:11:24.004319+00');
INSERT INTO public.roles (id, code, name, description, builtin, row_created_timestamp) VALUES (2, 'ADULT', 'Adult', NULL, 1, '2026-10-15 16:11:24.004319+00');
INSERT INTO public.roles (id, code, name, description, builtin, row_created_timestamp) VALUES (3, 'CHILD', 'Child', NULL, 1, '2026-10-15 16:11:24.004319+00');
INSERT INTO public.roles (id, code, name, description, builtin, row_created_timestamp) VALUES (4, 'GUEST', 'Guest', NULL, 1, '2026-10-15 16:11:24.004319+00');


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.role_permissions (role_id, permission_id) VALUES (1, 1);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 5);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 6);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 7);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 8);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 9);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 10);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 11);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 12);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 13);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 14);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (2, 23);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 6);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 14);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 16);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 19);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 21);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 24);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 29);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 31);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 32);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 33);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 34);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 35);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (3, 36);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (4, 31);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (4, 35);
INSERT INTO public.role_permissions (role_id, permission_id) VALUES (4, 36);


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: shopping_list; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: shopping_lists; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.shopping_lists (id, name, description, row_created_timestamp, shopping_location_id) VALUES (1, 'Shopping list', NULL, '2026-10-15 16:11:23+00', NULL);


--
-- Data for Name: shopping_locations; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: stock; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, row_created_timestamp, location_id, shopping_location_id, note, opened_amount, opened_qu_id, opened_tare, opened_measured_at, import_epoch) VALUES (1, 1, 3, '2027-01-31', '2026-10-15', '6ad0fb4ac2644', 2.5, 0, NULL, '2026-10-15 16:11:54+00', 2, NULL, NULL, NULL, NULL, NULL, NULL, 0);


--
-- Data for Name: stock_booking_lots; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.stock_booking_lots (id, booking_id, lot_id, amount, basis) VALUES (1, 1, 1, 3, 'recorded');


--
-- Data for Name: stock_entry_origins; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: stock_label_retirements; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: stock_row_lots; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.stock_row_lots (id, stock_row_id, lot_id, amount, basis) VALUES (1, 1, 1, 3, 'recorded');


--
-- Data for Name: system_db_changed_time; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.system_db_changed_time (id, changed_time) VALUES (1, '2026-10-15 16:11:54.806978+00');


--
-- Data for Name: task_categories; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: tasks; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: user_permissions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (1, 1, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (2, 34, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (3, 35, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (4, 36, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (5, 33, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (6, 32, 1);
INSERT INTO public.user_permissions (id, permission_id, user_id) VALUES (7, 31, 1);


--
-- Data for Name: user_roles; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: user_settings; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: user_settings_defaults; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.user_settings_defaults (key, value) VALUES ('night_mode', 'follow-system');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('auto_night_mode_enabled', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('auto_night_mode_time_range_from', '20:00');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('auto_night_mode_time_range_to', '07:00');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('auto_night_mode_time_range_goes_over_midnight', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('night_mode_enabled_internal', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('auto_reload_on_db_change', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('show_clock_in_header', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('keep_screen_on', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('keep_screen_on_when_fullscreen_card', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_location_id', '-1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_product_group_id', '-1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_qu_id', '-1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_default_due_days', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_treat_opened_as_out_of_stock', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('product_presets_default_stock_label_type', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_decimal_places_amounts', '4');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_decimal_places_prices_input', '2');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_decimal_places_prices_display', '2');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_auto_decimal_separator_prices', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_due_soon_days', '5');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_default_purchase_amount', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_default_consume_amount', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_default_consume_amount_use_quick_consume_amount', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('scan_mode_consume_enabled', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('scan_mode_purchase_enabled', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('show_icon_on_stock_overview_page_when_product_is_on_shopping_list', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('stock_overview_show_all_out_of_stock_products', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('show_purchased_date_on_purchase', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('show_warning_on_purchase_when_due_date_is_earlier_than_next', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_to_stock_workflow_auto_submit_when_prefilled', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_show_calendar', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_round_up', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_auto_add_below_min_stock_amount', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_auto_add_below_min_stock_amount_list_id', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_print_show_header', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_print_group_by_product_group', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('shopping_list_print_layout_type', 'table');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('recipe_ingredients_group_by_product_group', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('recipes_show_list_side_by_side', '1');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('recipes_show_ingredient_checkbox', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('chores_due_soon_days', '5');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('chores_overview_swap_tracking_buttons', '0');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('batteries_due_soon_days', '5');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('tasks_due_soon_days', '5');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('refill_warning_lead_days', '7');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('calendar_color_products', '#007bff');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('calendar_color_tasks', '#28a745');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('calendar_color_chores', '#ffc107');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('calendar_color_batteries', '#17a2b8');
INSERT INTO public.user_settings_defaults (key, value) VALUES ('calendar_color_meal_plan', '#6c757d');


--
-- Data for Name: userentities; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: userfield_values; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: userfields; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: userobjects; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Name: api_keys_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_keys_id_seq', 1, false);


--
-- Name: batteries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.batteries_id_seq', 1, false);


--
-- Name: battery_charge_cycles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.battery_charge_cycles_id_seq', 1, false);


--
-- Name: cache__products_average_price_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cache__products_average_price_id_seq', 1, true);


--
-- Name: cache__products_last_purchased_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cache__products_last_purchased_id_seq', 1, true);


--
-- Name: cache__quantity_unit_conversions_resolved_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cache__quantity_unit_conversions_resolved_id_seq', 1, true);


--
-- Name: chores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.chores_id_seq', 1, true);


--
-- Name: chores_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.chores_log_id_seq', 3, true);


--
-- Name: consumption_event_lines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_event_lines_id_seq', 1, false);


--
-- Name: consumption_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_events_id_seq', 1, false);


--
-- Name: consumption_mappings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_mappings_id_seq', 1, false);


--
-- Name: consumption_recipe_lines_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_recipe_lines_id_seq', 1, false);


--
-- Name: consumption_recipes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_recipes_id_seq', 1, false);


--
-- Name: consumption_refill_dates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_refill_dates_id_seq', 1, false);


--
-- Name: consumption_refill_fills_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_refill_fills_id_seq', 1, false);


--
-- Name: consumption_refill_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.consumption_refill_orders_id_seq', 1, false);


--
-- Name: equipment_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.equipment_id_seq', 1, false);


--
-- Name: files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.files_id_seq', 1, false);


--
-- Name: label_artifacts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_artifacts_id_seq', 1, false);


--
-- Name: label_assets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_assets_id_seq', 1, false);


--
-- Name: label_captures_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_captures_id_seq', 1, false);


--
-- Name: label_drivers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_drivers_id_seq', 1, false);


--
-- Name: label_idempotency_keys_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_idempotency_keys_id_seq', 1, false);


--
-- Name: label_media_profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_media_profiles_id_seq', 1, false);


--
-- Name: label_printers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_printers_id_seq', 1, false);


--
-- Name: label_render_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_render_requests_id_seq', 1, false);


--
-- Name: label_template_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_template_versions_id_seq', 6, false);


--
-- Name: label_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_templates_id_seq', 6, false);


--
-- Name: label_worker_capabilities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_worker_capabilities_id_seq', 1, false);


--
-- Name: label_worker_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_worker_sessions_id_seq', 1, false);


--
-- Name: label_workers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.label_workers_id_seq', 1, false);


--
-- Name: locations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.locations_id_seq', 3, false);


--
-- Name: login_attempts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.login_attempts_id_seq', 1, false);


--
-- Name: meal_plan_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.meal_plan_id_seq', 1, false);


--
-- Name: meal_plan_sections_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.meal_plan_sections_id_seq', 1, false);


--
-- Name: mqtt_product_entities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.mqtt_product_entities_id_seq', 1, false);


--
-- Name: mqtt_published_entities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.mqtt_published_entities_id_seq', 1, false);


--
-- Name: outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.outbox_id_seq', 1, false);


--
-- Name: permission_fields_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.permission_fields_id_seq', 47, false);


--
-- Name: permission_hierarchy_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.permission_hierarchy_id_seq', 42, false);


--
-- Name: print_attempts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.print_attempts_id_seq', 1, false);


--
-- Name: print_evidence_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.print_evidence_id_seq', 1, false);


--
-- Name: print_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.print_jobs_id_seq', 1, false);


--
-- Name: product_barcodes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_barcodes_id_seq', 1, false);


--
-- Name: product_groups_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_groups_id_seq', 1, false);


--
-- Name: product_location_min_stock_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_location_min_stock_id_seq', 1, false);


--
-- Name: product_substitutions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.product_substitutions_id_seq', 1, false);


--
-- Name: products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.products_id_seq', 1, true);


--
-- Name: quantity_unit_conversions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.quantity_unit_conversions_id_seq', 1, false);


--
-- Name: quantity_units_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.quantity_units_id_seq', 4, false);


--
-- Name: recipes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recipes_id_seq', 1, false);


--
-- Name: recipes_nestings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recipes_nestings_id_seq', 1, false);


--
-- Name: recipes_pos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.recipes_pos_id_seq', 1, false);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.roles_id_seq', 5, false);


--
-- Name: sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.sessions_id_seq', 1, false);


--
-- Name: shopping_list_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.shopping_list_id_seq', 1, false);


--
-- Name: shopping_lists_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.shopping_lists_id_seq', 2, false);


--
-- Name: shopping_locations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.shopping_locations_id_seq', 1, false);


--
-- Name: stock_booking_lots_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_booking_lots_id_seq', 1, true);


--
-- Name: stock_entry_origins_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_entry_origins_id_seq', 1, false);


--
-- Name: stock_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_id_seq', 1, true);


--
-- Name: stock_label_retirements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_label_retirements_id_seq', 1, false);


--
-- Name: stock_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_log_id_seq', 1, true);


--
-- Name: stock_row_lots_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stock_row_lots_id_seq', 1, true);


--
-- Name: storage_classes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.storage_classes_id_seq', 6, false);


--
-- Name: task_categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.task_categories_id_seq', 1, false);


--
-- Name: tasks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tasks_id_seq', 1, false);


--
-- Name: user_permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_permissions_id_seq', 8, false);


--
-- Name: user_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_settings_id_seq', 1, false);


--
-- Name: userentities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.userentities_id_seq', 1, false);


--
-- Name: userfield_values_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.userfield_values_id_seq', 1, false);


--
-- Name: userfields_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.userfields_id_seq', 1, false);


--
-- Name: userobjects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.userobjects_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 2, false);


--
-- PostgreSQL database dump complete
--

\unrestrict hzUaqGhaEmftl5JVhy3Cc63UL9M794Iolsbt1tch24HgtDRTAiyRoVrOGre55vC

