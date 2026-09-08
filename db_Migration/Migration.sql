-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 08, 2026 at 01:37 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `smallrest`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `sort_order`, `is_active`) VALUES
(1, 'Tea & Hot Drinks', 1, 1),
(2, 'Cold Drinks', 2, 1),
(3, 'Breakfast', 3, 1),
(4, 'Main Dishes', 4, 1),
(5, 'Snacks', 5, 1);

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `spent_on` date NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'General',
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_from` enum('drawer','other') NOT NULL DEFAULT 'drawer',
  `shift_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `needs_prep` tinyint(1) NOT NULL DEFAULT 1,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`id`, `category_id`, `name`, `price`, `cost_price`, `needs_prep`, `is_available`, `sort_order`, `created_at`) VALUES
(1, 1, 'Shaah Cadeys (Milk Tea)', 0.50, 0.20, 1, 1, 1, '2026-09-08 13:29:24'),
(2, 1, 'Black Tea', 0.30, 0.10, 1, 1, 2, '2026-09-08 13:29:24'),
(3, 1, 'Coffee', 1.00, 0.40, 1, 1, 3, '2026-09-08 13:29:24'),
(4, 1, 'Spiced Tea', 0.70, 0.25, 1, 1, 4, '2026-09-08 13:29:24'),
(5, 2, 'Bottled Water', 0.50, 0.30, 0, 1, 1, '2026-09-08 13:29:24'),
(6, 2, 'Soft Drink', 1.00, 0.60, 0, 1, 2, '2026-09-08 13:29:24'),
(7, 2, 'Fresh Mango Juice', 1.50, 0.70, 1, 1, 3, '2026-09-08 13:29:24'),
(8, 3, 'Canjeero with Tea', 1.50, 0.60, 1, 1, 1, '2026-09-08 13:29:24'),
(9, 3, 'Omelette', 2.00, 0.90, 1, 1, 2, '2026-09-08 13:29:24'),
(10, 3, 'Malawax', 1.00, 0.40, 1, 1, 3, '2026-09-08 13:29:24'),
(11, 4, 'Rice with Beef', 4.00, 2.00, 1, 1, 1, '2026-09-08 13:29:24'),
(12, 4, 'Rice with Chicken', 4.50, 2.20, 1, 1, 2, '2026-09-08 13:29:24'),
(13, 4, 'Pasta with Meat', 4.00, 1.90, 1, 1, 3, '2026-09-08 13:29:24'),
(14, 4, 'Fish with Rice', 5.00, 2.60, 1, 1, 4, '2026-09-08 13:29:24'),
(15, 5, 'Sambusa', 0.50, 0.20, 1, 1, 1, '2026-09-08 13:29:24'),
(16, 5, 'Bur (Doughnut)', 0.30, 0.10, 0, 1, 2, '2026-09-08 13:29:24');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_no` varchar(20) DEFAULT NULL,
  `order_type` enum('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
  `table_label` varchar(30) DEFAULT NULL,
  `status` enum('open','paid','void') NOT NULL DEFAULT 'open',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','mobile','card') DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `paid_by` int(10) UNSIGNED DEFAULT NULL,
  `shift_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_no`, `order_type`, `table_label`, `status`, `subtotal`, `discount`, `tax`, `total`, `paid_amount`, `change_amount`, `payment_method`, `note`, `created_by`, `paid_by`, `shift_id`, `created_at`, `paid_at`, `voided_at`, `void_reason`) VALUES
(1, '260908-0001', 'dine_in', NULL, 'void', 1.10, 0.00, 0.00, 1.10, 0.00, 0.00, NULL, NULL, 1, NULL, NULL, '2026-09-08 14:11:46', NULL, '2026-09-08 14:15:28', 'Voided by administrator'),
(2, '260908-0002', 'dine_in', NULL, 'void', 6.80, 0.00, 0.00, 6.80, 0.00, 0.00, NULL, NULL, 1, NULL, NULL, '2026-09-08 14:23:42', NULL, '2026-09-08 14:32:04', 'Voided by administrator');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `menu_item_id` int(10) UNSIGNED DEFAULT NULL,
  `item_name` varchar(100) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `qty` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `note` varchar(120) DEFAULT NULL,
  `kitchen_status` enum('pending','preparing','served') NOT NULL DEFAULT 'pending',
  `needs_prep` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `menu_item_id`, `item_name`, `unit_price`, `unit_cost`, `qty`, `line_total`, `note`, `kitchen_status`, `needs_prep`) VALUES
(1, 1, 1, 'Shaah Cadeys (Milk Tea)', 0.50, 0.20, 1, 0.50, NULL, 'served', 1),
(2, 1, 2, 'Black Tea', 0.30, 0.10, 1, 0.30, NULL, 'served', 1),
(3, 1, 16, 'Bur (Doughnut)', 0.30, 0.10, 1, 0.30, NULL, 'served', 0),
(4, 2, 5, 'Bottled Water', 0.50, 0.30, 1, 0.50, NULL, 'served', 0),
(5, 2, 8, 'Canjeero with Tea', 1.50, 0.60, 1, 1.50, NULL, 'served', 1),
(6, 2, 11, 'Rice with Beef', 4.00, 2.00, 1, 4.00, NULL, 'served', 1),
(7, 2, 15, 'Sambusa', 0.50, 0.20, 1, 0.50, NULL, 'served', 1),
(8, 2, 2, 'Black Tea', 0.30, 0.10, 1, 0.30, NULL, 'served', 1);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('currency', '$'),
('merchant_id', '125232'),
('merchant_name', 'Hormuud'),
('merchant_on_receipt', '1'),
('receipt_footer', 'Thank you — come again!'),
('shop_address', 'Hodan Taleex'),
('shop_name', 'Small Restaurant'),
('shop_phone', '615'),
('shop_tagline', 'Tea & Food'),
('tax_percent', '0.05'),
('ussd_prefix', '*789*');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `opening_float` decimal(10,2) NOT NULL DEFAULT 0.00,
  `closed_at` datetime DEFAULT NULL,
  `counted_cash` decimal(10,2) DEFAULT NULL,
  `expected_cash` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','cashier','waiter','kitchen') NOT NULL DEFAULT 'cashier',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'System Administrator', 'admin', '$2y$10$tKrPYcMFOY74w2zhx29aEewwOAvV6rlq9fjkYsAiASAmakHmyOM4S', 'admin', 1, '2026-09-08 13:29:24'),
(2, 'Front Cashier', 'cashier', '$2y$10$NODGHg3xlq.i1.Jcb2lbEeNxzsisRJ81iKSS7rUkMEnoTX2sTDLX.', 'cashier', 1, '2026-09-08 13:29:24'),
(3, 'Floor Waiter', 'waiter', '$2y$10$1osTS0htBN5voYr2h0upP.a8HNNkqcLZXhByA.vWOsnDWw03t5bAi', 'waiter', 1, '2026-09-08 13:29:24'),
(4, 'Kitchen Station', 'kitchen', '$2y$10$du.Cjvwz3v0A6GmVh9Z8MOwknRrCbYeJT/uGlIhiYc7Dg/IYrzUQW', 'kitchen', 1, '2026-09-08 13:29:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categories_name` (`name`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_exp_date` (`spent_on`),
  ADD KEY `ix_exp_shift` (`shift_id`),
  ADD KEY `fk_exp_user` (`user_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_items_category` (`category_id`),
  ADD KEY `ix_items_available` (`is_available`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orders_no` (`order_no`),
  ADD KEY `ix_orders_status` (`status`),
  ADD KEY `ix_orders_created` (`created_at`),
  ADD KEY `ix_orders_paid` (`paid_at`),
  ADD KEY `ix_orders_shift` (`shift_id`),
  ADD KEY `fk_orders_creator` (`created_by`),
  ADD KEY `fk_orders_payer` (`paid_by`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_oi_order` (`order_id`),
  ADD KEY `ix_oi_kitchen` (`kitchen_status`),
  ADD KEY `fk_oi_item` (`menu_item_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_shifts_user_status` (`user_id`,`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `ix_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_exp_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_orders_payer` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_oi_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shifts`
--
ALTER TABLE `shifts`
  ADD CONSTRAINT `fk_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
