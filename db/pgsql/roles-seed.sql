INSERT INTO permission_hierarchy (name, parent) SELECT 'STOCK_VIEW', id FROM permission_hierarchy WHERE name = 'STOCK' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'STOCK_VIEW');
INSERT INTO permission_hierarchy (name, parent) SELECT 'SHOPPINGLIST_VIEW', id FROM permission_hierarchy WHERE name = 'SHOPPINGLIST' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'SHOPPINGLIST_VIEW');
INSERT INTO permission_hierarchy (name, parent) SELECT 'CHORES_VIEW', id FROM permission_hierarchy WHERE name = 'CHORES' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'CHORES_VIEW');
INSERT INTO permission_hierarchy (name, parent) SELECT 'TASKS_VIEW', id FROM permission_hierarchy WHERE name = 'TASKS' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'TASKS_VIEW');
INSERT INTO permission_hierarchy (name, parent) SELECT 'RECIPES_VIEW', id FROM permission_hierarchy WHERE name = 'RECIPES' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'RECIPES_VIEW');
INSERT INTO permission_hierarchy (name, parent) SELECT 'MEALPLAN_VIEW', id FROM permission_hierarchy WHERE name = 'RECIPES_MEALPLAN' AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'MEALPLAN_VIEW');

-- Preserve every existing user's previously universal reads. New users get only
-- their configured defaults; user_roles deliberately starts empty.
INSERT INTO user_permissions (user_id, permission_id)
SELECT u.id, p.id FROM users u CROSS JOIN permission_hierarchy p
WHERE p.name IN ('STOCK_VIEW', 'SHOPPINGLIST_VIEW', 'CHORES_VIEW', 'TASKS_VIEW', 'RECIPES_VIEW', 'MEALPLAN_VIEW') AND NOT EXISTS (SELECT 1 FROM user_permissions up WHERE up.user_id = u.id AND up.permission_id = p.id);

INSERT INTO roles (code, name, builtin) VALUES ('ADMIN', 'Admin', 1) ON CONFLICT (code) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p WHERE r.code = 'ADMIN' AND p.name IN ('ADMIN') ON CONFLICT DO NOTHING;

INSERT INTO roles (code, name, builtin) VALUES ('ADULT', 'Adult', 1) ON CONFLICT (code) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p WHERE r.code = 'ADULT' AND p.name IN ('STOCK', 'SHOPPINGLIST', 'RECIPES', 'RECIPES_MEALPLAN', 'CHORES', 'TASKS', 'BATTERIES', 'EQUIPMENT', 'CALENDAR', 'USERS_READ', 'USERS_EDIT_SELF') ON CONFLICT DO NOTHING;

INSERT INTO roles (code, name, builtin) VALUES ('CHILD', 'Child', 1) ON CONFLICT (code) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p WHERE r.code = 'CHILD' AND p.name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_OPEN', 'SHOPPINGLIST_VIEW', 'SHOPPINGLIST_ITEMS_ADD', 'CHORES_VIEW', 'CHORE_TRACK_EXECUTION', 'TASKS_VIEW', 'TASKS_MARK_COMPLETED', 'RECIPES_VIEW', 'MEALPLAN_VIEW', 'CALENDAR', 'USERS_EDIT_SELF') ON CONFLICT DO NOTHING;

INSERT INTO roles (code, name, builtin) VALUES ('GUEST', 'Guest', 1) ON CONFLICT (code) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p WHERE r.code = 'GUEST' AND p.name IN ('STOCK_VIEW', 'RECIPES_VIEW', 'MEALPLAN_VIEW') ON CONFLICT DO NOTHING;
