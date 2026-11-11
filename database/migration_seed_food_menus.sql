-- =====================================================
-- SEED: FOOD MENUS, PACKAGES & BUNDLES
-- Janet's Quality Catering Services
-- Data sourced from Janet's official marketing materials:
--   - All-in Wedding & Debut packages (Classica / Royale)
--   - Catering Menus 9 - 14
--   - Party Tray price list, Packed Meals, Bundles & Puto Box
-- =====================================================
-- HOW TO INSTALL:
--   Import AFTER catering_db.sql and event_bookings_module.sql.
--   This wipes the placeholder demo menu data and inserts the
--   real Janet's catalog. Safe to re-run.
-- =====================================================

USE janets_catering;

SET FOREIGN_KEY_CHECKS = 0;

-- Clear existing placeholder menu data (keep bookings intact)
DELETE FROM `menu_bundles`;
DELETE FROM `menu_items`;
-- Only remove packages that are not referenced by a booking
DELETE FROM `menu_packages`;
ALTER TABLE `menu_packages` AUTO_INCREMENT = 1;
ALTER TABLE `menu_items` AUTO_INCREMENT = 1;
ALTER TABLE `menu_bundles` AUTO_INCREMENT = 1;

-- =====================================================
-- MENU PACKAGES (per-head catering packages)
-- price_per_guest = standard per-head rate, plus 10% service charge in excess of 100pax
-- =====================================================
INSERT INTO `menu_packages` (`package_name`, `tier`, `price_per_guest`, `description`, `icon`, `sort_order`) VALUES
('Classica Package (Wedding & Debut)', 'Silver', 550.00, '4-hour all-in service: fresh garden salad, soup, pasta, veggies, seafoods, chicken, pork & dessert with steamed rice and drinks. Elegant buffet set-up, banquet chairs, VIP plated service for 20 pax, host, lights & sounds, 2-tier fondant cake, photobooth, HMUA, photo & video and on-the-day coordination.', 'bx-crown', 1),
('Royale Package (Wedding & Debut)', 'Platinum', 750.00, '4-hour premium all-in service: 2 appetizers, salad, soup, pasta, vegetable, seafoods, chicken, pork, beef & 2 desserts with steamed rice and drinks. Upgraded stylish stage backdrop, Tiffany & ghost chairs, LED wall, 3-tier cake, magnetic photobooth, professional photo & video, upgraded styling and full coordination team.', 'bx-diamond', 2),
('Catering Menu 9', 'Gold', 675.00, 'Nacho cheese dip, creamy pumpkin soup, cheesy baked macaroni, buttered broccoli, grilled blue marlin, grilled chicken in mushroom sauce, Korean beef stew, parsley rice, mango pandan salad & cucumber lemonade. (₱635-750/head depending on pax)', 'bx-restaurant', 3),
('Catering Menu 10', 'Gold', 620.00, 'Tuna canape, nacho cheese dip, ham & cheese roll, cheesy baked macaroni, roasted potato mushroom, baked prawn in pesto, creamy chicken in sundried tomato, Korean beef stew, parsley rice, mango pandan salad & house blend iced tea. (₱600-825/head)', 'bx-restaurant', 4),
('Catering Menu 11', 'Gold', 780.00, 'Beef wrap, mini clubhouse sandwich, fresh garden salad, chicken & corn soup, cheesy baked macaroni, stir fry mixed vegetables, baked fish fillet with parmesan, grilled chicken teriyaki de luxe, smoked pork belly, creamy beef mushroom, parsley rice, custard cream puff, mango panna cotta & cucumber lemonade. (₱750-950/head)', 'bx-restaurant', 5),
('Catering Menu 12', 'Gold', 765.00, 'Ham & cheese roll or tuna canape, kani mango salad, cream of mushroom soup, meaty lasagna, roasted broccoli zucchini mushroom, baked prawn in pesto, creamy chicken in sundried tomato, pork belly in Asian sauce, roast beef with mashed potato, parsley rice, custard cream puff & mango panna cotta. (₱750-950/head)', 'bx-restaurant', 6),
('Catering Menu 13', 'Gold', 765.00, 'Cheesy mini burger, roka salata, creamy pumpkin soup, fusilli pasta with 2 sauces, mixed vegetable with shrimp, spicy shrimp in salted egg, roast basil chicken in chimichurri, pork mongolian de luxe, beef lengua green olive, parsley rice, custard flan, buko pandan con lychee & cucumber lemonade. (₱750-950/head)', 'bx-restaurant', 7),
('Catering Menu 14', 'Gold', 780.00, 'Kani mango salad, creamy pumpkin soup, fettuccine pasta w/ alfredo & marinara, fresh lumpiang ubod roll, roast basil lemon grass chicken, fish fillet w/ cilantro & mango salsa, pork mongolian de luxe, beef lengua w/ green olive, parsley rice, custard flan, mango panna cotta & cucumber lemonade. (₱750-950/head)', 'bx-restaurant', 8);

-- =====================================================
-- MENU ITEMS (customizable dishes grouped by course)
-- Sourced from Janet's Party Tray / Catering dish list
-- upgrade_price = extra per-guest charge (0 = included)
-- =====================================================
INSERT INTO `menu_items` (`item_name`, `course`, `description`, `availability_note`, `upgrade_price`) VALUES
-- Appetizers & Salads
('Fresh Garden Salad with Vinaigrette', 'Appetizers', 'Crisp greens with balsamic vinaigrette', 'Included', 0.00),
('Kani Mango Salad', 'Appetizers', 'Crab sticks and ripe mango with roasted sesame dressing', 'Included', 0.00),
('Roka Salata with Vinaigrette Dressing', 'Appetizers', 'Arugula salad with light vinaigrette', 'Included', 0.00),
('Clubhouse Sandwich', 'Appetizers', 'Triple-decker clubhouse sandwich', 'Included', 0.00),
('Nachos with Cheese Dip & Salsa', 'Appetizers', 'Tortilla chips with cheese dip and salsa', 'Included', 0.00),
('Mango Pandan Salad', 'Appetizers', 'Sweet mango and pandan cream salad', 'Included', 0.00),
('Fresh Lumpiang Ubod Roll', 'Appetizers', 'Fresh heart-of-palm spring roll with peanut sauce', 'Included', 0.00),
-- Soups
('Creamy Pumpkin Soup', 'Sides', 'Velvety roasted pumpkin soup', 'Included', 0.00),
('Cream of Mushroom Soup', 'Sides', 'Classic creamy mushroom soup', 'Included', 0.00),
('Chicken and Corn Soup', 'Sides', 'Hearty chicken and sweet corn soup', 'Included', 0.00),
-- Pasta
('Cheesy Baked Macaroni', 'Sides', 'Our signature baked macaroni', 'Included', 0.00),
('Meaty Lasagna', 'Sides', 'Layered pasta with rich meat sauce', '+45 upgrade', 45.00),
('Fettuccine Mushroom in Alfredo Sauce', 'Sides', 'Creamy alfredo fettuccine with mushrooms', '+40 upgrade', 40.00),
('Penne Bacon Chicken in Parmesan Carbonara', 'Sides', 'Penne in parmesan carbonara with bacon & chicken', '+40 upgrade', 40.00),
('Fusilli Pasta with 2 Sauces', 'Sides', 'Fusilli with alfredo and marinara sauce', 'Included', 0.00),
-- Chicken
('Grilled Chicken in Mushroom Sauce', 'Main Courses', 'Grilled chicken with creamy mushroom sauce', 'Included', 0.00),
('Grilled Chicken Teriyaki de Luxe', 'Main Courses', 'Grilled chicken in teriyaki glaze', 'Included', 0.00),
('Roast Basil Lemon Grass Chicken', 'Main Courses', 'Roasted chicken with basil and lemongrass', 'Included', 0.00),
('Chicken Cordon Bleu with Dijon Cream Sauce', 'Main Courses', 'Breaded chicken filled with ham and cheese', '+35 upgrade', 35.00),
('Garlic Parmesan Chicken Wings', 'Main Courses', 'Crispy wings tossed in garlic parmesan', '+30 upgrade', 30.00),
('Roast Basil Chicken in Chimichurri', 'Main Courses', 'Roast chicken with herbed chimichurri', 'Included', 0.00),
-- Pork
('Smoked Pork Belly in Hickory Sauce', 'Main Courses', 'Smoked pork belly glazed in hickory sauce', '+40 upgrade', 40.00),
('Pork Belly in Asian Sauce', 'Main Courses', 'Tender pork belly in savory Asian sauce', 'Included', 0.00),
('Pork Mongolian de Luxe', 'Main Courses', 'Pork strips in rich Mongolian sauce', 'Included', 0.00),
('Janet''s Crispy Pata', 'Main Courses', 'Deep-fried crispy pork leg', '+80 upgrade', 80.00),
('Patatim (Chinese Braised Pork Leg)', 'Main Courses', 'Chinese-style braised pork leg', '+70 upgrade', 70.00),
-- Beef
('Korean Beef Stew in Gochujang Essence', 'Main Courses', 'Beef stew with gochujang and sesame seeds', '+50 upgrade', 50.00),
('Roast Beef with Mashed Potato', 'Main Courses', 'Roast beef served with mashed potato', '+55 upgrade', 55.00),
('Beef Lengua with Green Olive & Champignon', 'Main Courses', 'Ox tongue with green olives and mushrooms', '+60 upgrade', 60.00),
('Creamy Beef Mushroom', 'Main Courses', 'Beef strips in creamy mushroom sauce', '+45 upgrade', 45.00),
('Beef Broccoli in Oyster Sauce', 'Main Courses', 'Beef and broccoli in oyster sauce', '+45 upgrade', 45.00),
-- Fish & Seafoods
('Grilled Blue Marlin in Lemon Sauce', 'Main Courses', 'Grilled blue marlin with zesty lemon sauce', 'Included', 0.00),
('Baked Fish Fillet with Parmesan', 'Main Courses', 'Baked fish fillet topped with parmesan', 'Included', 0.00),
('Fish Fillet with Cilantro & Mango Salsa', 'Main Courses', 'Fish fillet with fresh cilantro mango salsa', 'Included', 0.00),
('Baked Prawn in Pesto Sauce', 'Main Courses', 'Oven-baked prawns in pesto', '+60 upgrade', 60.00),
('Spicy Shrimp in Salted Egg', 'Main Courses', 'Shrimp tossed in spicy salted egg sauce', '+55 upgrade', 55.00),
('Mixed Vegetable with Shrimp', 'Main Courses', 'Stir-fried mixed vegetables with shrimp', 'Included', 0.00),
-- Vegetables & Sides
('Stir Fry Mixed Vegetables', 'Sides', 'Assorted vegetables stir fried', 'Included', 0.00),
('Roasted Broccoli, Zucchini & Mushroom', 'Sides', 'Oven roasted seasonal vegetables', 'Included', 0.00),
('Buttered Vegetable with Cheese', 'Sides', 'Buttered vegetables with melted cheese', 'Included', 0.00),
('Parsley Rice', 'Sides', 'Steamed rice tossed with parsley', 'Included', 0.00),
('Steamed Rice', 'Sides', 'Plain steamed rice', 'Included', 0.00),
-- Desserts
('Custard Cream Puff', 'Desserts', 'Choux pastry filled with custard cream', 'Included', 0.00),
('Mango Panna Cotta', 'Desserts', 'Silky panna cotta with mango', 'Included', 0.00),
('Buko Pandan con Lychee', 'Desserts', 'Young coconut and pandan with lychee', 'Included', 0.00),
('Custard Flan (Leche Flan)', 'Desserts', 'Classic caramel custard', 'Included', 0.00),
('Creamy Fruit Salad', 'Desserts', 'Mixed fruits in cream', '+25 upgrade', 25.00),
-- Beverages
('Cucumber Lemonade', 'Beverages', 'Refreshing cucumber lemonade', 'Included', 0.00),
('House Blend Iced Tea', 'Beverages', 'House blend sweetened iced tea', 'Included', 0.00),
('Purified Water', 'Beverages', 'Bottled purified water', 'Included', 0.00);

-- =====================================================
-- MENU BUNDLES / ADD-ONS
-- Packed Meals, Lucky/Salu Salo/Barkada/Family Bundles,
-- Group Platters, Puto Box and event add-on services.
-- price_mode flat = total price; per_guest = per head.
-- =====================================================
INSERT INTO `menu_bundles` (`bundle_name`, `description`, `bundle_type`, `price`, `price_mode`) VALUES
-- Packed Meals (min 20 packs)
('Packed Meal Set 1', 'Pork barbeque, chicken sotanghon guisado, Vietnamese spring roll with nuoc sauce, steamed rice. (min 20 packs)', 'Food Bundle', 200.00, 'flat'),
('Packed Meal Set 4', 'Grilled chicken in mushroom steak sauce, pork belly in hickory sauce, roasted potato & mushroom with bacon bits, steamed rice. (min 20 packs)', 'Food Bundle', 290.00, 'flat'),
('Packed Meal Set 5', 'Fall-off-the-bone pork ribs in special BBQ sauce, baked fish fillet with parmesan, fettuccini in alfredo sauce, steamed rice. (min 20 packs)', 'Food Bundle', 320.00, 'flat'),
('Packed Meal Set 7', 'Grilled blue marlin in lemon sauce, chicken cordon bleu, cheesy baked macaroni, leche flan, steamed rice. (min 20 packs)', 'Food Bundle', 320.00, 'flat'),
('Packed Meal Set 8', 'Grilled chicken teriyaki de luxe, roast beef with mashed potato, fettucine mushroom in alfredo, steamed rice. (min 20 packs)', 'Food Bundle', 345.00, 'flat'),
-- Salu Salo Meals (good for 10 pax)
('Salu Salo Meal 1', 'Pansit canton, chopsuey, Janet''s fried chicken, plain rice, leche flan & softdrinks. Good for 10 pax.', 'Food Bundle', 2750.00, 'flat'),
('Salu Salo Meal 3', 'Bihon canton guisado, chopsuey special, chicken BBQ, siomai, pork sinigang, shrimp tempura, beef kare-kare, plain rice, leche flan & softdrinks. Good for 10 pax.', 'Food Bundle', 4400.00, 'flat'),
('Salu Salo Meal 5', 'Chami, lumpiang ubod, inihaw bangus, inihaw tilapia, Janet''s fried chicken, sinigang na baka, beef broccoli, plain rice, leche flan & softdrinks. Good for 10 pax.', 'Food Bundle', 5450.00, 'flat'),
-- Lucky Bundles
('Lucky Bundle 1', 'Patatim braised pork leg, buttered chicken, miki bihon, special seafoods fried rice, free steamed siomai.', 'Food Bundle', 1999.00, 'flat'),
('Lucky Bundle 3', 'Janet''s fried chicken, pork asado, miki bihon, special seafoods fried rice, free steamed siomai.', 'Food Bundle', 2120.00, 'flat'),
-- Barkada Meals
('Barkada Meal A', 'Pork asado, boneless bangus, chopsuey, 4 rice, 1.5 softdrinks & squidballs.', 'Food Bundle', 585.00, 'flat'),
('Barkada Meal C', 'Pork sinigang, mixed vegetables, 4pcs chicken BBQ, shanghai & squidballs, 4 rice, 1.5 softdrinks.', 'Food Bundle', 755.00, 'flat'),
-- Family Bundles
('Family Bundle 3', 'Chicken inasal, vegetable with pork asado, beef mushroom, steamed rice.', 'Food Bundle', 495.00, 'flat'),
('Family Bundle 6', 'Crispy pata (good for 4-6 pax), chopsuey special, sinigang sa miso, chicken barbeque, steamed rice.', 'Food Bundle', 1195.00, 'flat'),
-- Group Platters
('Pansit Platter with Bagnet', 'Pansit platter topped with bagnet.', 'Food Bundle', 350.00, 'flat'),
('Pork Barbecue Platter', 'Platter of grilled pork barbecue.', 'Food Bundle', 470.00, 'flat'),
('Chicken Bucket (6pcs)', 'Bucket of 6 pieces fried chicken.', 'Food Bundle', 520.00, 'flat'),
('Seafoods Paella', 'Large seafoods paella platter.', 'Food Bundle', 2800.00, 'flat'),
-- Puto Box
('Puto Box (30 pcs)', 'Box of 30 pieces freshly steamed cheese puto.', 'Add-on', 330.00, 'flat'),
('Puto Box (60 pcs)', 'Box of 60 pieces freshly steamed cheese puto.', 'Add-on', 630.00, 'flat'),
-- Event Add-on Services
('Ceremony Arrangement', '1 wedding arch, 12 standee with flower arrangement, 50 pcs banquet chairs with cover.', 'Service', 9500.00, 'flat'),
('Upgraded Backdrop', 'Premium upgraded event backdrop styling.', 'Service', 10000.00, 'flat'),
('Entrance Arch', 'Decorated entrance arch with floral accents.', 'Service', 3500.00, 'flat'),
('Lechon Whole Roast', 'Whole roasted pig, Filipino style.', 'Food Bundle', 350.00, 'per_guest'),
('Dessert Station', 'Assorted cakes, pastries and chocolate fountain.', 'Food Bundle', 8.00, 'per_guest');

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;
