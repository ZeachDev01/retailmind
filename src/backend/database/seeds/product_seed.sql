-- All 60 products from product_seed.csv; select your local/development database before import.
-- Requires the current RetailMind schema and CREATE ROUTINE permission (MySQL/MariaDB).
-- Run standalone in phpMyAdmin Import or the mysql CLI; do not import inside another transaction.
-- Existing products and inventory are preserved. New products start with zero stock.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS retailmind_import_product_seed;
DELIMITER $$
CREATE PROCEDURE retailmind_import_product_seed()
BEGIN
    DECLARE store_id INT;
    DECLARE branch_count INT;
    DECLARE seed_lock INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS retailmind_product_seed;
        IF seed_lock = 1 THEN
            DO RELEASE_LOCK('retailmind_product_seed');
        END IF;
        RESIGNAL;
    END;

    SELECT GET_LOCK('retailmind_product_seed', 10) INTO seed_lock;
    IF COALESCE(seed_lock, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Could not acquire product seed lock.';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('branches','categories','products','inventory')
        AND ENGINE = 'InnoDB') <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seed requires current InnoDB RetailMind tables.';
    END IF;

    START TRANSACTION;
    SELECT COUNT(*), MIN(b.branch_id) INTO branch_count, store_id
    FROM branches b
    WHERE EXISTS (SELECT 1 FROM users u WHERE u.branch_id = b.branch_id)
       OR EXISTS (SELECT 1 FROM products p WHERE p.branch_id = b.branch_id);
    IF branch_count = 0 THEN
        SELECT COUNT(*), MIN(branch_id) INTO branch_count, store_id FROM branches WHERE status = 'active';
    END IF;
    IF branch_count <> 1 OR NOT EXISTS (SELECT 1 FROM branches WHERE branch_id = store_id AND status = 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seed requires one resolved active Store; apply Store setup/consolidation first.';
    END IF;

    CREATE TEMPORARY TABLE retailmind_product_seed (
        sku VARCHAR(50) NOT NULL PRIMARY KEY,
        barcode VARCHAR(50) NOT NULL UNIQUE,
        product_name VARCHAR(150) NOT NULL,
        brand VARCHAR(100) NOT NULL,
        category_name VARCHAR(100) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        cost_price DECIMAL(10,2) NOT NULL,
        reorder_level INT NOT NULL,
        preferred_supplier VARCHAR(150) NOT NULL,
        supplier_lead_time_days INT NOT NULL,
        safety_stock INT NOT NULL,
        minimum_order_quantity INT NOT NULL,
        units_per_package INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO retailmind_product_seed VALUES
    ('SKU-BEV-001', '890100000001', 'Spring Water 500ml', 'AquaPure', 'Beverages', '0.75', '0.32', '24', 'ClearSpring Distributors', '3', '12', '24', '24'),
    ('SKU-BEV-002', '890100000002', 'Spring Water 1.5L', 'AquaPure', 'Beverages', '1.35', '0.62', '18', 'ClearSpring Distributors', '3', '8', '12', '12'),
    ('SKU-BEV-003', '890100000003', 'Sparkling Water 330ml', 'FizzWell', 'Beverages', '1.1', '0.48', '18', 'ClearSpring Distributors', '4', '8', '24', '24'),
    ('SKU-BEV-004', '890100000004', 'Cola Can 330ml', 'RefreshCo', 'Beverages', '1.25', '0.58', '24', 'Metro Beverage Supply', '4', '12', '24', '24'),
    ('SKU-BEV-005', '890100000005', 'Orange Soda 500ml', 'RefreshCo', 'Beverages', '1.5', '0.7', '18', 'Metro Beverage Supply', '4', '8', '12', '12'),
    ('SKU-BEV-006', '890100000006', 'Apple Juice 1L', 'Orchard Gold', 'Beverages', '2.85', '1.45', '12', 'FreshFields Wholesale', '5', '6', '6', '6'),
    ('SKU-BEV-007', '890100000007', 'Iced Tea Lemon 500ml', 'TeaTrail', 'Beverages', '1.75', '0.82', '18', 'Metro Beverage Supply', '4', '8', '12', '12'),
    ('SKU-BEV-008', '890100000008', 'Energy Drink 250ml', 'VoltUp', 'Beverages', '2.4', '1.2', '18', 'Metro Beverage Supply', '4', '8', '12', '12'),
    ('SKU-SNK-001', '890100000009', 'Potato Chips Sea Salt 150g', 'CrispHouse', 'Snacks', '2.25', '1.05', '16', 'SnackSource Ltd', '5', '8', '12', '12'),
    ('SKU-SNK-002', '890100000010', 'Tortilla Chips Nacho 200g', 'CrispHouse', 'Snacks', '2.75', '1.32', '12', 'SnackSource Ltd', '5', '6', '12', '12'),
    ('SKU-SNK-003', '890100000011', 'Peanuts Roasted 250g', 'NutHarvest', 'Snacks', '3.2', '1.7', '10', 'SnackSource Ltd', '6', '5', '6', '6'),
    ('SKU-SNK-004', '890100000012', 'Trail Mix 200g', 'NutHarvest', 'Snacks', '4.5', '2.45', '8', 'SnackSource Ltd', '6', '4', '6', '6'),
    ('SKU-SNK-005', '890100000013', 'Chocolate Bar Milk 45g', 'CocoaPeak', 'Snacks', '1.4', '0.62', '24', 'SweetGoods Supply', '5', '12', '24', '24'),
    ('SKU-SNK-006', '890100000014', 'Granola Bar Oats 6 Pack', 'DailyGrain', 'Snacks', '3.9', '1.95', '10', 'FreshFields Wholesale', '5', '5', '6', '6'),
    ('SKU-SNK-007', '890100000015', 'Popcorn Microwave 3 Pack', 'KernelJoy', 'Snacks', '3.5', '1.72', '8', 'SnackSource Ltd', '6', '4', '6', '6'),
    ('SKU-SNK-008', '890100000016', 'Crackers Original 250g', 'TableTime', 'Snacks', '2.6', '1.2', '10', 'SnackSource Ltd', '5', '5', '12', '12'),
    ('SKU-DRY-001', '890100000017', 'Long Grain Rice 1kg', 'HarvestHome', 'Pantry', '3.25', '1.68', '12', 'Global Grocers', '7', '6', '12', '12'),
    ('SKU-DRY-002', '890100000018', 'All Purpose Flour 1kg', 'HarvestHome', 'Pantry', '2.4', '1.15', '10', 'Global Grocers', '7', '5', '12', '12'),
    ('SKU-DRY-003', '890100000019', 'Granulated Sugar 1kg', 'HarvestHome', 'Pantry', '2.55', '1.2', '10', 'Global Grocers', '7', '5', '12', '12'),
    ('SKU-DRY-004', '890100000020', 'Spaghetti 500g', 'PastaMio', 'Pantry', '1.85', '0.82', '14', 'Global Grocers', '7', '7', '20', '20'),
    ('SKU-DRY-005', '890100000021', 'Macaroni 500g', 'PastaMio', 'Pantry', '1.85', '0.82', '14', 'Global Grocers', '7', '7', '20', '20'),
    ('SKU-DRY-006', '890100000022', 'Baked Beans 400g', 'PantryBest', 'Pantry', '1.65', '0.74', '18', 'Global Grocers', '7', '9', '24', '24'),
    ('SKU-DRY-007', '890100000023', 'Tomato Sauce 500g', 'PantryBest', 'Pantry', '2.35', '1.08', '12', 'Global Grocers', '7', '6', '12', '12'),
    ('SKU-DRY-008', '890100000024', 'Peanut Butter 340g', 'NutHarvest', 'Pantry', '4.75', '2.45', '8', 'Global Grocers', '7', '4', '6', '6'),
    ('SKU-DRY-009', '890100000025', 'Instant Noodles Chicken 5 Pack', 'NoodleTime', 'Pantry', '3.2', '1.5', '16', 'Global Grocers', '7', '8', '12', '12'),
    ('SKU-DRY-010', '890100000026', 'Cooking Oil Sunflower 1L', 'GoldenDrop', 'Pantry', '4.6', '2.3', '8', 'Global Grocers', '7', '4', '6', '6'),
    ('SKU-DAI-001', '890100000027', 'Whole Milk 1L', 'MeadowFresh', 'Dairy', '2.1', '1.22', '12', 'FreshFields Wholesale', '2', '6', '12', '12'),
    ('SKU-DAI-002', '890100000028', 'Low Fat Milk 1L', 'MeadowFresh', 'Dairy', '2.1', '1.22', '12', 'FreshFields Wholesale', '2', '6', '12', '12'),
    ('SKU-DAI-003', '890100000029', 'Plain Yogurt 500g', 'CreamVale', 'Dairy', '3.25', '1.72', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-DAI-004', '890100000030', 'Greek Yogurt 150g', 'CreamVale', 'Dairy', '1.45', '0.72', '12', 'FreshFields Wholesale', '2', '6', '12', '12'),
    ('SKU-DAI-005', '890100000031', 'Cheddar Cheese 200g', 'CheeseCraft', 'Dairy', '4.9', '2.65', '8', 'FreshFields Wholesale', '3', '4', '6', '6'),
    ('SKU-DAI-006', '890100000032', 'Butter Salted 250g', 'MeadowFresh', 'Dairy', '4.25', '2.2', '8', 'FreshFields Wholesale', '3', '4', '6', '6'),
    ('SKU-BRD-001', '890100000033', 'White Bread Loaf 600g', 'BakeHouse', 'Bakery', '2.8', '1.42', '10', 'DailyBake Bakery', '1', '5', '10', '10'),
    ('SKU-BRD-002', '890100000034', 'Whole Wheat Bread Loaf 600g', 'BakeHouse', 'Bakery', '3.1', '1.58', '10', 'DailyBake Bakery', '1', '5', '10', '10'),
    ('SKU-BRD-003', '890100000035', 'Croissants 4 Pack', 'BakeHouse', 'Bakery', '4.2', '2.18', '6', 'DailyBake Bakery', '1', '3', '6', '6'),
    ('SKU-BRD-004', '890100000036', 'Burger Buns 6 Pack', 'BakeHouse', 'Bakery', '3.4', '1.7', '8', 'DailyBake Bakery', '1', '4', '8', '8'),
    ('SKU-PRO-001', '890100000037', 'Red Apples 1kg', 'Orchard Gold', 'Produce', '3.75', '1.95', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-PRO-002', '890100000038', 'Bananas 1kg', 'GreenValley', 'Produce', '2.25', '1.05', '10', 'FreshFields Wholesale', '2', '5', '6', '6'),
    ('SKU-PRO-003', '890100000039', 'Oranges 1kg', 'Orchard Gold', 'Produce', '3.4', '1.7', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-PRO-004', '890100000040', 'Potatoes 2kg', 'GreenValley', 'Produce', '4.1', '2.05', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-PRO-005', '890100000041', 'Onions 1kg', 'GreenValley', 'Produce', '2.7', '1.25', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-PRO-006', '890100000042', 'Tomatoes 500g', 'GreenValley', 'Produce', '2.9', '1.45', '8', 'FreshFields Wholesale', '2', '4', '6', '6'),
    ('SKU-FRZ-001', '890100000043', 'Frozen Peas 500g', 'FrostGarden', 'Frozen Foods', '3.6', '1.85', '8', 'ColdChain Suppliers', '5', '4', '6', '6'),
    ('SKU-FRZ-002', '890100000044', 'Frozen Mixed Vegetables 1kg', 'FrostGarden', 'Frozen Foods', '5.25', '2.72', '6', 'ColdChain Suppliers', '5', '3', '6', '6'),
    ('SKU-FRZ-003', '890100000045', 'Frozen French Fries 1kg', 'FrostGarden', 'Frozen Foods', '4.8', '2.4', '8', 'ColdChain Suppliers', '5', '4', '6', '6'),
    ('SKU-FRZ-004', '890100000046', 'Vanilla Ice Cream 1L', 'IceCrown', 'Frozen Foods', '6.5', '3.35', '6', 'ColdChain Suppliers', '5', '3', '6', '6'),
    ('SKU-HOU-001', '890100000047', 'Dishwashing Liquid 750ml', 'CleanNest', 'Household', '3.85', '1.9', '8', 'HomeCare Distributors', '6', '4', '6', '6'),
    ('SKU-HOU-002', '890100000048', 'Laundry Detergent 2L', 'CleanNest', 'Household', '8.9', '4.55', '6', 'HomeCare Distributors', '6', '3', '4', '4'),
    ('SKU-HOU-003', '890100000049', 'Paper Towels 2 Roll', 'SoftNest', 'Household', '3.75', '1.82', '10', 'HomeCare Distributors', '6', '5', '12', '12'),
    ('SKU-HOU-004', '890100000050', 'Toilet Tissue 4 Roll', 'SoftNest', 'Household', '5.9', '2.95', '10', 'HomeCare Distributors', '6', '5', '12', '12'),
    ('SKU-HOU-005', '890100000051', 'Garbage Bags Medium 20 Pack', 'CleanNest', 'Household', '4.4', '2.1', '8', 'HomeCare Distributors', '6', '4', '6', '6'),
    ('SKU-PER-001', '890100000052', 'Bar Soap Aloe 100g', 'FreshTouch', 'Personal Care', '1.6', '0.68', '12', 'PersonalCare Wholesale', '7', '6', '24', '24'),
    ('SKU-PER-002', '890100000053', 'Shampoo Daily Care 400ml', 'FreshTouch', 'Personal Care', '5.75', '2.8', '8', 'PersonalCare Wholesale', '7', '4', '6', '6'),
    ('SKU-PER-003', '890100000054', 'Toothpaste Mint 100ml', 'SmileBright', 'Personal Care', '3.2', '1.42', '12', 'PersonalCare Wholesale', '7', '6', '12', '12'),
    ('SKU-PER-004', '890100000055', 'Toothbrush Medium', 'SmileBright', 'Personal Care', '2.5', '1.05', '12', 'PersonalCare Wholesale', '7', '6', '12', '12'),
    ('SKU-PER-005', '890100000056', 'Deodorant Roll On 50ml', 'FreshTouch', 'Personal Care', '4.1', '1.95', '8', 'PersonalCare Wholesale', '7', '4', '6', '6'),
    ('SKU-BAB-001', '890100000057', 'Baby Wipes 64 Count', 'LittleSteps', 'Baby Care', '4.9', '2.45', '8', 'FamilyGoods Supply', '7', '4', '6', '6'),
    ('SKU-BAB-002', '890100000058', 'Baby Diapers Size 4 20 Pack', 'LittleSteps', 'Baby Care', '11.5', '6.1', '6', 'FamilyGoods Supply', '7', '3', '4', '4'),
    ('SKU-PET-001', '890100000059', 'Dog Food Chicken 2kg', 'PawPantry', 'Pet Care', '9.75', '5.2', '6', 'PetSupply Wholesale', '7', '3', '4', '4'),
    ('SKU-PET-002', '890100000060', 'Cat Food Tuna 400g', 'PawPantry', 'Pet Care', '2.9', '1.42', '10', 'PetSupply Wholesale', '7', '5', '12', '12');

    IF EXISTS (
        SELECT 1 FROM retailmind_product_seed s JOIN products p ON p.branch_id = store_id
        AND (p.sku IN (s.sku, s.barcode) OR p.barcode IN (s.sku, s.barcode)
          OR p.case_barcode IN (s.sku, s.barcode) OR p.pack_barcode IN (s.sku, s.barcode))
        WHERE p.sku <> s.sku OR p.barcode <> s.barcode
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seed identifier collision; transaction rolled back.';
    END IF;

    INSERT INTO categories (category_name)
    SELECT DISTINCT s.category_name FROM retailmind_product_seed s
    WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = s.category_name);

    INSERT INTO products (sku, barcode, product_name, brand, category_id, unit_price, cost_price,
        reorder_level, supplier, preferred_supplier, supplier_lead_time_days, safety_stock,
        minimum_order_quantity, units_per_package, branch_id)
    SELECT s.sku, s.barcode, s.product_name, s.brand,
        (SELECT MIN(c.category_id) FROM categories c WHERE c.category_name = s.category_name),
        s.unit_price, s.cost_price, s.reorder_level, s.preferred_supplier, s.preferred_supplier,
        s.supplier_lead_time_days, s.safety_stock, s.minimum_order_quantity, s.units_per_package, store_id
    FROM retailmind_product_seed s
    WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.branch_id = store_id AND p.sku = s.sku);

    INSERT INTO inventory (product_id, quantity_on_hand)
    SELECT p.product_id, 0 FROM products p JOIN retailmind_product_seed s ON p.sku = s.sku AND p.barcode = s.barcode
    WHERE p.branch_id = store_id AND NOT EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.product_id);

    COMMIT;
    DROP TEMPORARY TABLE retailmind_product_seed;
    DO RELEASE_LOCK('retailmind_product_seed');
END$$
DELIMITER ;

CALL retailmind_import_product_seed();
DROP PROCEDURE retailmind_import_product_seed;
