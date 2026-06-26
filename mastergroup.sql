-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 04, 2026 at 08:29 PM
-- Server version: 10.6.25-MariaDB-cll-lve
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hypnotherapy_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `hdb_master_groups`
--
CREATE DATABASE IF NOT EXISTS user_hypnotherapy_db2;
USE user_hypnotherapy_db2;
CREATE TABLE `hdb_master_groups` (
  `id` int(11) NOT NULL,
  `core_group` varchar(100) NOT NULL,
  `industry_desc` text NOT NULL,
  `created_at` varchar(20) NOT NULL,
  `updated_at` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hdb_master_groups`
--

INSERT INTO `hdb_master_groups` (`id`, `core_group`, `industry_desc`, `created_at`, `updated_at`) VALUES
(1, 'Health, Wellness & Mindset', 'Health & Wellness', '1777477437', '1777477437'),
(2, 'Real Estate, Home & Living', 'Real Estate & Decor', '1777477437', '1777477437'),
(3, 'Food, Dining & Hospitality', 'Hospitality & Dining', '1777477437', '1777477437'),
(4, 'Business, Finance & Tech', 'Corporate & B2B', '1777477437', '1777477437'),
(5, 'Entertainment, Sports & Media', 'Creative & Media', '1777477437', '1777477437'),
(6, 'Community, Education & Services', 'Auto & Industrial', '1777477437', '1777477437'),
(7, 'Community, Education & Services', 'Events & Socials', '1777477437', '1777477437'),
(8, 'Community, Education & Services', 'Education & Learning', '1777477437', '1777477437'),
(9, 'Business, Finance & Tech', 'Retail & E-commerce', '1777477437', '1777477437'),
(10, 'Lifestyle, Fashion & Creator', 'Travel & Tourism', '1777477437', '1777477437'),
(11, 'Lifestyle, Fashion & Creator', 'Creator & Personal Brand', '1777477887', '1777477887'),
(12, 'Entertainment, Sports & Media', 'Entertainment & Viral Content', '1777477887', '1777477887'),
(13, 'Business, Finance & Tech', 'Technology & Gadgets', '1777477887', '1777477887'),
(14, 'Entertainment, Sports & Media', 'Gaming & Esports', '1777477887', '1777477887'),
(15, 'Health, Wellness & Mindset', 'Lifestyle & Self-Improvement', '1777477887', '1777477887'),
(16, 'Entertainment, Sports & Media', 'Sports & Athletics', '1777477887', '1777477887'),
(25, 'Community, Education & Services', 'Pet Services', '2026-04-29 16:51:01', '2026-04-29 16:51:01'),
(26, 'Lifestyle, Fashion & Creator', 'Beauty & Personal Care (Salons/Spa)', '2026-04-29 16:53:42', '2026-04-29 16:53:42'),
(27, 'Lifestyle, Fashion & Creator', 'Lifestyle', '2026-04-29 20:59:21', '2026-04-29 20:59:21'),
(28, 'Food, Dining & Hospitality', 'Food & Dining', '2026-04-29 21:02:15', '2026-04-29 21:02:15'),
(29, 'Lifestyle, Fashion & Creator', 'Travel', '2026-04-29 21:46:55', '2026-04-29 21:46:55'),
(30, 'Health, Wellness & Mindset', 'Healthcare', '2026-04-29 22:15:26', '2026-04-29 22:15:26'),
(31, 'Lifestyle, Fashion & Creator', 'Family / Lifestyle', '2026-04-29 22:26:07', '2026-04-29 22:26:07'),
(32, 'Business, Finance & Tech', 'Business', '2026-04-29 22:46:13', '2026-04-29 22:46:13'),
(33, 'Real Estate, Home & Living', 'Home & Living', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(34, 'Lifestyle, Fashion & Creator', 'Parenting & Kids', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(35, 'Lifestyle, Fashion & Creator', 'Luxury & Premium', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(36, 'Community, Education & Services', 'Wedding & Bridal', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(37, 'Food, Dining & Hospitality', 'Food Content & Recipes', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(38, 'Food, Dining & Hospitality', 'Street Food & Local Eats', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(39, 'Food, Dining & Hospitality', 'Nightlife & Clubs', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(40, 'Health, Wellness & Mindset', 'Fitness & Gym Culture', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(41, 'Health, Wellness & Mindset', 'Mental Health & Mindfulness', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(42, 'Something Else / Other', 'Fashion & Apparel', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(43, 'Real Estate, Home & Living', 'DIY & Crafts', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(44, 'Entertainment, Sports & Media', 'Photography & Videography', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(45, 'Lifestyle, Fashion & Creator', 'Influencer Marketing', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(46, 'Entertainment, Sports & Media', 'Short-form Content (Reels/TikTok)', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(47, 'Entertainment, Sports & Media', 'Streaming & OTT Content', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(48, 'Business, Finance & Tech', 'Unboxing & Product Reviews', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(49, 'Health, Wellness & Mindset', 'Motivation & Success', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(50, 'Business, Finance & Tech', 'Finance & Investing', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(51, 'Business, Finance & Tech', 'Side Hustles & Online Income', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(52, 'Business, Finance & Tech', 'AI & Automation', '2026-04-29 22:50:24', '2026-04-29 22:50:24'),
(53, 'Community, Education & Services', 'Transportation', '2026-04-30 07:03:07', '2026-04-30 07:03:07'),
(54, 'Entertainment, Sports & Media', 'Music', '2026-04-30 07:08:31', '2026-04-30 07:08:31'),
(55, 'Business, Finance & Tech', 'Technology', '2026-04-30 07:41:34', '2026-04-30 07:41:34'),
(56, 'Community, Education & Services', 'Animals', '2026-04-30 08:08:32', '2026-04-30 08:08:32'),
(57, 'Community, Education & Services', 'Cattle Farm', '2026-04-30 08:10:36', '2026-04-30 08:10:36'),
(58, 'Community, Education & Services', 'Funeral', '2026-04-30 08:12:40', '2026-04-30 08:12:40'),
(59, 'Something Else / Other', 'Others', '2026-05-01 20:48:57', '2026-05-01 20:48:57'),
(60, 'Real Estate, Home & Living', 'Nature', '2026-05-01 21:53:44', '2026-05-01 21:53:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `hdb_master_groups`
--
ALTER TABLE `hdb_master_groups`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `hdb_master_groups`
--
ALTER TABLE `hdb_master_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
