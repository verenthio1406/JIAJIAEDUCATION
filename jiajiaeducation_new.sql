-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 17, 2025 at 04:55 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jiajiaeducation_new`
--

-- --------------------------------------------------------

--
-- Table structure for table `cabang`
--

CREATE TABLE `cabang` (
  `cabang_id` int(11) NOT NULL,
  `nama_cabang` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cabang`
--

INSERT INTO `cabang` (`cabang_id`, `nama_cabang`) VALUES
(1, 'Cabang Medit'),
(9, 'Cabang Menteng');

-- --------------------------------------------------------

--
-- Table structure for table `cabangguru`
--

CREATE TABLE `cabangguru` (
  `id` int(11) NOT NULL,
  `guru_id` int(11) NOT NULL,
  `cabang_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cabangguru`
--

INSERT INTO `cabangguru` (`id`, `guru_id`, `cabang_id`) VALUES
(32, 8, 1),
(35, 10, 1),
(38, 13, 1),
(41, 15, 1),
(42, 15, 9);

-- --------------------------------------------------------

--
-- Table structure for table `datales`
--

CREATE TABLE `datales` (
  `datales_id` int(11) NOT NULL,
  `jenistingkat_id` int(11) NOT NULL,
  `cabang_id` int(11) NOT NULL,
  `harga` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `datales`
--

INSERT INTO `datales` (`datales_id`, `jenistingkat_id`, `cabang_id`, `harga`) VALUES
(12, 12, 1, 800000),
(13, 13, 1, 880000),
(14, 11, 1, 800000),
(15, 14, 1, 880000),
(18, 16, 1, 880000),
(19, 12, 9, 500000);

-- --------------------------------------------------------

--
-- Table structure for table `guru`
--

CREATE TABLE `guru` (
  `guru_id` int(11) NOT NULL,
  `nama_guru` varchar(100) NOT NULL,
  `status` enum('aktif','nonaktif','cuti') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guru`
--

INSERT INTO `guru` (`guru_id`, `nama_guru`, `status`) VALUES
(8, 'Mr. Bobby', 'aktif'),
(10, 'Mr. Alex', 'aktif'),
(13, 'Mr. Randy', 'aktif'),
(15, 'Guru B', 'aktif');

-- --------------------------------------------------------

--
-- Table structure for table `guru_datales`
--

CREATE TABLE `guru_datales` (
  `id` int(11) NOT NULL,
  `guru_id` int(11) NOT NULL,
  `datales_id` int(11) NOT NULL,
  `cabang_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guru_datales`
--

INSERT INTO `guru_datales` (`id`, `guru_id`, `datales_id`, `cabang_id`) VALUES
(41, 8, 14, 1),
(40, 8, 15, 1),
(39, 10, 12, 1),
(38, 10, 13, 1),
(46, 13, 18, 1),
(49, 15, 14, 1),
(48, 15, 19, 9);

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_pertemuan`
--

CREATE TABLE `jadwal_pertemuan` (
  `jadwal_id` int(11) NOT NULL,
  `pembayaran_id` int(11) NOT NULL COMMENT 'Link ke pembayaran',
  `slot_id` int(11) DEFAULT NULL,
  `siswa_id` int(11) NOT NULL,
  `datales_id` int(11) NOT NULL,
  `bulan_ke` int(11) NOT NULL DEFAULT 1 COMMENT 'Bulan ke-N dalam semester (1-6)',
  `semester_ke` int(11) NOT NULL DEFAULT 1,
  `tahun_ajaran` varchar(20) DEFAULT '2024/2025',
  `guru_id` int(11) DEFAULT NULL COMMENT 'Guru yang mengajar',
  `pertemuan_ke` int(11) NOT NULL COMMENT '1, 2, 3, 4, dst',
  `tanggal_pertemuan` date NOT NULL COMMENT '7 Mei, 14 Mei, 21 Mei, 28 Mei',
  `tanggal_reschedule` date DEFAULT NULL,
  `catatan_reschedule` text DEFAULT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `status_pertemuan` enum('hadir','tidak_hadir','scheduled') DEFAULT 'scheduled',
  `catatan` text DEFAULT NULL,
  `is_reschedule` tinyint(1) DEFAULT 0 COMMENT 'Apakah ini jadwal reschedule dari bulan lalu',
  `is_history` tinyint(1) DEFAULT 0,
  `reschedule_dari_jadwal_id` int(11) DEFAULT NULL COMMENT 'ID jadwal asli yang di-reschedule'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jadwal_pertemuan`
--

INSERT INTO `jadwal_pertemuan` (`jadwal_id`, `pembayaran_id`, `slot_id`, `siswa_id`, `datales_id`, `bulan_ke`, `semester_ke`, `tahun_ajaran`, `guru_id`, `pertemuan_ke`, `tanggal_pertemuan`, `tanggal_reschedule`, `catatan_reschedule`, `jam_mulai`, `jam_selesai`, `status_pertemuan`, `catatan`, `is_reschedule`, `is_history`, `reschedule_dari_jadwal_id`) VALUES
(1, 0, NULL, 72, 14, 1, 1, '2024', NULL, 1, '2025-11-19', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(2, 0, NULL, 72, 14, 1, 1, '2024', NULL, 2, '2025-11-26', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(3, 0, NULL, 72, 14, 2, 1, '2024', NULL, 1, '2025-12-03', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(4, 0, NULL, 72, 14, 2, 1, '2024', NULL, 2, '2025-12-10', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(5, 0, NULL, 72, 14, 3, 1, '2024', NULL, 1, '2025-12-17', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(6, 0, NULL, 72, 14, 3, 1, '2024', NULL, 2, '2025-12-24', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(7, 0, NULL, 72, 14, 4, 1, '2024', NULL, 1, '2025-12-31', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(8, 0, NULL, 72, 14, 4, 1, '2024', NULL, 2, '2026-01-07', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(9, 0, NULL, 72, 14, 5, 1, '2024', NULL, 1, '2026-01-14', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(10, 0, NULL, 72, 14, 5, 1, '2024', NULL, 2, '2026-01-21', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(11, 0, NULL, 72, 14, 6, 1, '2024', NULL, 1, '2026-01-28', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(12, 0, NULL, 72, 14, 6, 1, '2024', NULL, 2, '2026-02-04', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(61, 0, NULL, 77, 12, 1, 1, '2024', NULL, 1, '2025-11-24', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(62, 0, NULL, 77, 12, 1, 1, '2024', NULL, 2, '2025-12-01', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(63, 0, NULL, 77, 12, 2, 1, '2024', NULL, 1, '2025-12-08', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(64, 0, NULL, 77, 12, 2, 1, '2024', NULL, 2, '2025-12-15', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(65, 0, NULL, 77, 12, 3, 1, '2024', NULL, 1, '2025-12-22', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(66, 0, NULL, 77, 12, 3, 1, '2024', NULL, 2, '2025-12-29', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(67, 0, NULL, 77, 12, 4, 1, '2024', NULL, 1, '2026-01-05', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(68, 0, NULL, 77, 12, 4, 1, '2024', NULL, 2, '2026-01-12', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(69, 0, NULL, 77, 12, 5, 1, '2024', NULL, 1, '2026-01-19', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(70, 0, NULL, 77, 12, 5, 1, '2024', NULL, 2, '2026-01-26', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(71, 0, NULL, 77, 12, 6, 1, '2024', NULL, 1, '2026-02-02', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(72, 0, NULL, 77, 12, 6, 1, '2024', NULL, 2, '2026-02-09', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(73, 0, NULL, 78, 12, 1, 1, '2024', NULL, 1, '2025-11-24', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(74, 0, NULL, 78, 12, 1, 1, '2024', NULL, 2, '2025-12-01', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(75, 0, NULL, 78, 12, 2, 1, '2024', NULL, 1, '2025-12-08', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(76, 0, NULL, 78, 12, 2, 1, '2024', NULL, 2, '2025-12-15', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(77, 0, NULL, 78, 12, 3, 1, '2024', NULL, 1, '2025-12-22', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(78, 0, NULL, 78, 12, 3, 1, '2024', NULL, 2, '2025-12-29', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(79, 0, NULL, 78, 12, 4, 1, '2024', NULL, 1, '2026-01-05', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(80, 0, NULL, 78, 12, 4, 1, '2024', NULL, 2, '2026-01-12', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(81, 0, NULL, 78, 12, 5, 1, '2024', NULL, 1, '2026-01-19', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(82, 0, NULL, 78, 12, 5, 1, '2024', NULL, 2, '2026-01-26', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(83, 0, NULL, 78, 12, 6, 1, '2024', NULL, 1, '2026-02-02', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(84, 0, NULL, 78, 12, 6, 1, '2024', NULL, 2, '2026-02-09', NULL, NULL, '13:00:00', '13:45:00', 'scheduled', NULL, 0, 0, NULL),
(85, 0, NULL, 79, 17, 1, 1, '2024', NULL, 1, '2025-11-26', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(86, 0, NULL, 79, 17, 1, 1, '2024', NULL, 2, '2025-12-03', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(87, 0, NULL, 79, 17, 2, 1, '2024', NULL, 1, '2025-12-10', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(88, 0, NULL, 79, 17, 2, 1, '2024', NULL, 2, '2025-12-17', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(89, 0, NULL, 79, 17, 3, 1, '2024', NULL, 1, '2025-12-24', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(90, 0, NULL, 79, 17, 3, 1, '2024', NULL, 2, '2025-12-31', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(91, 0, NULL, 79, 17, 4, 1, '2024', NULL, 1, '2026-01-07', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(92, 0, NULL, 79, 17, 4, 1, '2024', NULL, 2, '2026-01-14', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(93, 0, NULL, 79, 17, 5, 1, '2024', NULL, 1, '2026-01-21', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(94, 0, NULL, 79, 17, 5, 1, '2024', NULL, 2, '2026-01-28', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(95, 0, NULL, 79, 17, 6, 1, '2024', NULL, 1, '2026-02-04', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(96, 0, NULL, 79, 17, 6, 1, '2024', NULL, 2, '2026-02-11', NULL, NULL, '14:00:00', '14:45:00', 'hadir', '', 0, 1, NULL),
(97, 0, NULL, 79, 17, 1, 2, '2025', NULL, 1, '2026-02-18', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(98, 0, NULL, 79, 17, 1, 2, '2025', NULL, 2, '2026-02-25', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(99, 0, NULL, 79, 17, 2, 2, '2025', NULL, 1, '2026-03-04', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(100, 0, NULL, 79, 17, 2, 2, '2025', NULL, 2, '2026-03-11', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(101, 0, NULL, 79, 17, 3, 2, '2025', NULL, 1, '2026-03-18', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(102, 0, NULL, 79, 17, 3, 2, '2025', NULL, 2, '2026-03-25', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(103, 0, NULL, 79, 17, 4, 2, '2025', NULL, 1, '2026-04-01', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(104, 0, NULL, 79, 17, 4, 2, '2025', NULL, 2, '2026-04-08', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(105, 0, NULL, 79, 17, 5, 2, '2025', NULL, 1, '2026-04-15', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(106, 0, NULL, 79, 17, 5, 2, '2025', NULL, 2, '2026-04-22', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(107, 0, NULL, 79, 17, 6, 2, '2025', NULL, 1, '2026-04-29', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(108, 0, NULL, 79, 17, 6, 2, '2025', NULL, 2, '2026-05-06', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(109, 0, NULL, 80, 17, 1, 1, '2024', NULL, 1, '2025-11-26', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(110, 0, NULL, 80, 17, 1, 1, '2024', NULL, 2, '2025-12-03', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(111, 0, NULL, 80, 17, 2, 1, '2024', NULL, 1, '2025-12-10', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(112, 0, NULL, 80, 17, 2, 1, '2024', NULL, 2, '2025-12-17', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(113, 0, NULL, 80, 17, 3, 1, '2024', NULL, 1, '2025-12-24', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(114, 0, NULL, 80, 17, 3, 1, '2024', NULL, 2, '2025-12-31', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(115, 0, NULL, 80, 17, 4, 1, '2024', NULL, 1, '2026-01-07', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(116, 0, NULL, 80, 17, 4, 1, '2024', NULL, 2, '2026-01-14', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(117, 0, NULL, 80, 17, 5, 1, '2024', NULL, 1, '2026-01-21', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(118, 0, NULL, 80, 17, 5, 1, '2024', NULL, 2, '2026-01-28', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(119, 0, NULL, 80, 17, 6, 1, '2024', NULL, 1, '2026-02-04', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(120, 0, NULL, 80, 17, 6, 1, '2024', NULL, 2, '2026-02-11', NULL, NULL, '14:00:00', '14:45:00', 'scheduled', NULL, 0, 0, NULL),
(121, 0, NULL, 81, 18, 1, 1, '2024', NULL, 1, '2025-11-27', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(122, 0, NULL, 81, 18, 1, 1, '2024', NULL, 2, '2025-12-04', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(123, 0, NULL, 81, 18, 2, 1, '2024', NULL, 1, '2025-12-11', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(124, 0, NULL, 81, 18, 2, 1, '2024', NULL, 2, '2025-12-18', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(125, 0, NULL, 81, 18, 3, 1, '2024', NULL, 1, '2025-12-25', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(126, 0, NULL, 81, 18, 3, 1, '2024', NULL, 2, '2026-01-01', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(127, 0, NULL, 81, 18, 4, 1, '2024', NULL, 1, '2026-01-08', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(128, 0, NULL, 81, 18, 4, 1, '2024', NULL, 2, '2026-01-15', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(129, 0, NULL, 81, 18, 5, 1, '2024', NULL, 1, '2026-01-22', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(130, 0, NULL, 81, 18, 5, 1, '2024', NULL, 2, '2026-01-29', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(131, 0, NULL, 81, 18, 6, 1, '2024', NULL, 1, '2026-02-05', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(132, 0, NULL, 81, 18, 6, 1, '2024', NULL, 2, '2026-02-12', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 1, NULL),
(133, 0, NULL, 81, 18, 1, 2, '2025', NULL, 1, '2026-02-19', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(134, 0, NULL, 81, 18, 1, 2, '2025', NULL, 2, '2026-02-26', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(135, 0, NULL, 81, 18, 2, 2, '2025', NULL, 1, '2026-03-05', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(136, 0, NULL, 81, 18, 2, 2, '2025', NULL, 2, '2026-03-12', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(137, 0, NULL, 81, 18, 3, 2, '2025', NULL, 1, '2026-03-19', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(138, 0, NULL, 81, 18, 3, 2, '2025', NULL, 2, '2026-03-26', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(139, 0, NULL, 81, 18, 4, 2, '2025', NULL, 1, '2026-04-02', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(140, 0, NULL, 81, 18, 4, 2, '2025', NULL, 2, '2026-04-09', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(141, 0, NULL, 81, 18, 5, 2, '2025', NULL, 1, '2026-04-16', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(142, 0, NULL, 81, 18, 5, 2, '2025', NULL, 2, '2026-04-23', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(143, 0, NULL, 81, 18, 6, 2, '2025', NULL, 1, '2026-04-30', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(144, 0, NULL, 81, 18, 6, 2, '2025', NULL, 2, '2026-05-07', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(145, 0, NULL, 82, 18, 1, 1, '2024', NULL, 1, '2025-11-27', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(146, 0, NULL, 82, 18, 1, 1, '2024', NULL, 2, '2025-12-04', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(147, 0, NULL, 82, 18, 2, 1, '2024', NULL, 1, '2025-12-11', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(148, 0, NULL, 82, 18, 2, 1, '2024', NULL, 2, '2025-12-18', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(149, 0, NULL, 82, 18, 3, 1, '2024', NULL, 1, '2025-12-25', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(150, 0, NULL, 82, 18, 3, 1, '2024', NULL, 2, '2026-01-01', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(151, 0, NULL, 82, 18, 4, 1, '2024', NULL, 1, '2026-01-08', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(152, 0, NULL, 82, 18, 4, 1, '2024', NULL, 2, '2026-01-15', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(153, 0, NULL, 82, 18, 5, 1, '2024', NULL, 1, '2026-01-22', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(154, 0, NULL, 82, 18, 5, 1, '2024', NULL, 2, '2026-01-29', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(155, 0, NULL, 82, 18, 6, 1, '2024', NULL, 1, '2026-02-05', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(156, 0, NULL, 82, 18, 6, 1, '2024', NULL, 2, '2026-02-12', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(157, 0, NULL, 83, 14, 1, 1, '2024', NULL, 1, '2025-11-26', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(158, 0, NULL, 83, 14, 1, 1, '2024', NULL, 2, '2025-12-03', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(159, 0, NULL, 83, 14, 2, 1, '2024', NULL, 1, '2025-11-20', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(160, 0, NULL, 83, 14, 2, 1, '2024', NULL, 2, '2025-11-27', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(161, 0, NULL, 83, 14, 3, 1, '2024', NULL, 1, '2025-12-24', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(162, 0, NULL, 83, 14, 3, 1, '2024', NULL, 2, '2025-12-31', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(163, 0, NULL, 83, 14, 4, 1, '2024', NULL, 1, '2026-01-07', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(164, 0, NULL, 83, 14, 4, 1, '2024', NULL, 2, '2026-01-14', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(165, 0, NULL, 83, 14, 5, 1, '2024', NULL, 1, '2026-01-21', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(166, 0, NULL, 83, 14, 5, 1, '2024', NULL, 2, '2026-01-28', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(167, 0, NULL, 83, 14, 6, 1, '2024', NULL, 1, '2026-02-04', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(168, 0, NULL, 83, 14, 6, 1, '2024', NULL, 2, '2026-02-11', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(169, 0, NULL, 84, 12, 1, 1, '2024', NULL, 1, '2025-12-08', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(170, 0, NULL, 84, 12, 1, 1, '2024', NULL, 2, '2025-12-15', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(171, 0, NULL, 84, 12, 2, 1, '2024', NULL, 1, '2025-12-22', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(172, 0, NULL, 84, 12, 2, 1, '2024', NULL, 2, '2025-12-29', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(173, 0, NULL, 84, 12, 3, 1, '2024', NULL, 1, '2026-01-05', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(174, 0, NULL, 84, 12, 3, 1, '2024', NULL, 2, '2026-01-12', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(175, 0, NULL, 84, 12, 4, 1, '2024', NULL, 1, '2026-01-19', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(176, 0, NULL, 84, 12, 4, 1, '2024', NULL, 2, '2026-01-26', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(177, 0, NULL, 84, 12, 5, 1, '2024', NULL, 1, '2026-02-02', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(178, 0, NULL, 84, 12, 5, 1, '2024', NULL, 2, '2026-02-09', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(179, 0, NULL, 84, 12, 6, 1, '2024', NULL, 1, '2026-02-16', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(180, 0, NULL, 84, 12, 6, 1, '2024', NULL, 2, '2026-02-23', NULL, NULL, '13:00:00', '14:00:00', 'hadir', '', 0, 1, NULL),
(181, 0, NULL, 85, 18, 1, 1, '2024', NULL, 1, '2025-12-11', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(182, 0, NULL, 85, 18, 1, 1, '2024', NULL, 2, '2025-12-18', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(183, 0, NULL, 85, 18, 2, 1, '2024', NULL, 1, '2025-12-25', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(184, 0, NULL, 85, 18, 2, 1, '2024', NULL, 2, '2026-01-01', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(185, 0, NULL, 85, 18, 3, 1, '2024', NULL, 1, '2026-01-08', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(186, 0, NULL, 85, 18, 3, 1, '2024', NULL, 2, '2026-01-15', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(187, 0, NULL, 85, 18, 4, 1, '2024', NULL, 1, '2026-01-22', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(188, 0, NULL, 85, 18, 4, 1, '2024', NULL, 2, '2026-01-29', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(189, 0, NULL, 85, 18, 5, 1, '2024', NULL, 1, '2026-02-05', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(190, 0, NULL, 85, 18, 5, 1, '2024', NULL, 2, '2026-02-12', NULL, NULL, '10:00:00', '10:45:00', 'scheduled', NULL, 0, 0, NULL),
(191, 0, NULL, 85, 18, 6, 1, '2024', NULL, 1, '2026-02-19', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(192, 0, NULL, 85, 18, 6, 1, '2024', NULL, 2, '2026-02-26', NULL, NULL, '10:00:00', '10:45:00', 'hadir', '', 0, 0, NULL),
(193, 0, NULL, 84, 12, 1, 2, '2025', NULL, 1, '2026-03-02', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(194, 0, NULL, 84, 12, 1, 2, '2025', NULL, 2, '2026-03-09', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(195, 0, NULL, 84, 12, 2, 2, '2025', NULL, 1, '2026-03-16', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(196, 0, NULL, 84, 12, 2, 2, '2025', NULL, 2, '2026-03-23', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(197, 0, NULL, 84, 12, 3, 2, '2025', NULL, 1, '2026-03-30', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(198, 0, NULL, 84, 12, 3, 2, '2025', NULL, 2, '2026-04-06', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(199, 0, NULL, 84, 12, 4, 2, '2025', NULL, 1, '2026-04-13', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(200, 0, NULL, 84, 12, 4, 2, '2025', NULL, 2, '2026-04-20', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(201, 0, NULL, 84, 12, 5, 2, '2025', NULL, 1, '2026-04-27', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(202, 0, NULL, 84, 12, 5, 2, '2025', NULL, 2, '2026-05-04', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(203, 0, NULL, 84, 12, 6, 2, '2025', NULL, 1, '2026-05-11', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL),
(204, 0, NULL, 84, 12, 6, 2, '2025', NULL, 2, '2026-05-18', NULL, NULL, '13:00:00', '14:00:00', 'scheduled', NULL, 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_slot`
--

CREATE TABLE `jadwal_slot` (
  `slot_id` int(11) NOT NULL,
  `cabangguruID` int(11) NOT NULL,
  `jenistingkat_id` int(11) DEFAULT NULL,
  `hari` enum('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu') NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `tipe_kelas` enum('private','group') NOT NULL,
  `kapasitas_maksimal` int(11) DEFAULT 1,
  `status` enum('aktif','nonaktif') DEFAULT 'aktif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jadwal_slot`
--

INSERT INTO `jadwal_slot` (`slot_id`, `cabangguruID`, `jenistingkat_id`, `hari`, `jam_mulai`, `jam_selesai`, `tipe_kelas`, `kapasitas_maksimal`, `status`) VALUES
(11, 35, 12, 'Senin', '13:00:00', '14:00:00', 'private', 1, 'aktif'),
(12, 35, 13, 'Selasa', '16:00:00', '17:45:00', 'private', 1, 'aktif'),
(13, 32, 11, 'Rabu', '10:00:00', '10:45:00', 'private', 1, 'aktif'),
(16, 38, 16, 'Kamis', '10:00:00', '10:45:00', 'private', 1, 'aktif'),
(18, 41, 11, 'Senin', '11:00:00', '11:45:00', 'private', 1, 'aktif');

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_slot_history`
--

CREATE TABLE `jadwal_slot_history` (
  `history_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `cabangguruID` int(11) NOT NULL,
  `jenistingkat_id` int(11) NOT NULL,
  `hari` varchar(20) NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `tipe_kelas` enum('private','group') NOT NULL,
  `kapasitas_maksimal` int(11) NOT NULL,
  `status` enum('aktif','nonaktif') NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jadwal_slot_history`
--

INSERT INTO `jadwal_slot_history` (`history_id`, `slot_id`, `cabangguruID`, `jenistingkat_id`, `hari`, `jam_mulai`, `jam_selesai`, `tipe_kelas`, `kapasitas_maksimal`, `status`, `changed_at`, `changed_by`, `change_reason`) VALUES
(1, 11, 35, 12, 'Senin', '13:00:00', '13:45:00', 'private', 1, 'aktif', '2025-11-24 15:14:28', 1, 'Perubahan jadwal slot'),
(2, 12, 35, 13, 'Selasa', '16:00:00', '16:45:00', 'private', 1, 'aktif', '2025-11-24 15:18:10', 1, 'Perubahan jadwal slot');

-- --------------------------------------------------------

--
-- Table structure for table `jenisles`
--

CREATE TABLE `jenisles` (
  `jenisles_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jenisles`
--

INSERT INTO `jenisles` (`jenisles_id`, `name`) VALUES
(10, 'Mandarin'),
(6, 'Musik');

-- --------------------------------------------------------

--
-- Table structure for table `jenistingkat`
--

CREATE TABLE `jenistingkat` (
  `jenistingkat_id` int(11) NOT NULL,
  `tipeles_id` int(11) NOT NULL,
  `nama_jenistingkat` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jenistingkat`
--

INSERT INTO `jenistingkat` (`jenistingkat_id`, `tipeles_id`, `nama_jenistingkat`) VALUES
(11, 8, 'Pemula'),
(12, 9, 'Pemula'),
(13, 9, 'Beginner 1'),
(14, 8, 'Beginner 1'),
(16, 14, 'Beginner 2');

-- --------------------------------------------------------

--
-- Table structure for table `pembayaran`
--

CREATE TABLE `pembayaran` (
  `pembayaran_id` int(11) NOT NULL,
  `siswa_id` int(11) NOT NULL,
  `datales_id` int(11) NOT NULL,
  `bulan_ke` int(11) DEFAULT NULL COMMENT 'Pembayaran untuk bulan ke berapa (1-6)',
  `semester_ke` int(11) DEFAULT 1,
  `tahun_ajaran` varchar(20) DEFAULT NULL,
  `periode_bulan` varchar(20) DEFAULT NULL COMMENT 'Contoh: Januari 2025',
  `tanggal_transfer` date DEFAULT NULL,
  `jumlah_bayar` decimal(10,2) NOT NULL,
  `bukti_transfer` varchar(255) DEFAULT NULL COMMENT 'Path file bukti transfer',
  `status_pembayaran` enum('waiting_verification','verified','rejected') DEFAULT 'waiting_verification',
  `is_archived` tinyint(1) DEFAULT 0,
  `total_pertemuan` int(11) NOT NULL DEFAULT 4
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pembayaran`
--

INSERT INTO `pembayaran` (`pembayaran_id`, `siswa_id`, `datales_id`, `bulan_ke`, `semester_ke`, `tahun_ajaran`, `periode_bulan`, `tanggal_transfer`, `jumlah_bayar`, `bukti_transfer`, `status_pembayaran`, `is_archived`, `total_pertemuan`) VALUES
(130, 83, 14, 1, 1, '2024/2025', 'November 2025', '0000-00-00', 800000.00, NULL, '', 0, 2),
(131, 83, 14, 2, 1, '2024/2025', 'December 2025', '0000-00-00', 800000.00, NULL, '', 0, 2),
(132, 83, 14, 3, 1, '2024/2025', 'January 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(133, 83, 14, 4, 1, '2024/2025', 'February 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(134, 83, 14, 5, 1, '2024/2025', 'March 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(135, 83, 14, 6, 1, '2024/2025', 'April 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(136, 84, 12, 1, 1, '2024/2025', 'December 2025', '0000-00-00', 800000.00, NULL, '', 1, 2),
(137, 84, 12, 2, 1, '2024/2025', 'January 2026', '0000-00-00', 800000.00, NULL, '', 1, 2),
(138, 84, 12, 3, 1, '2024/2025', 'February 2026', '0000-00-00', 800000.00, NULL, '', 1, 2),
(139, 84, 12, 4, 1, '2024/2025', 'March 2026', '0000-00-00', 800000.00, NULL, '', 1, 2),
(140, 84, 12, 5, 1, '2024/2025', 'April 2026', '0000-00-00', 800000.00, NULL, '', 1, 2),
(141, 84, 12, 6, 1, '2024/2025', 'May 2026', '0000-00-00', 800000.00, NULL, '', 1, 2),
(142, 85, 18, 1, 1, '2024/2025', 'December 2025', '2025-12-04', 880000.00, 'uploads/bukti_transfer/bukti_142_1764823873.pdf', 'verified', 0, 2),
(143, 85, 18, 2, 1, '2024/2025', 'January 2026', '0000-00-00', 880000.00, NULL, '', 0, 2),
(144, 85, 18, 3, 1, '2024/2025', 'February 2026', '0000-00-00', 880000.00, NULL, '', 0, 2),
(145, 85, 18, 4, 1, '2024/2025', 'March 2026', '0000-00-00', 880000.00, NULL, '', 0, 2),
(146, 85, 18, 5, 1, '2024/2025', 'April 2026', '0000-00-00', 880000.00, NULL, '', 0, 2),
(147, 85, 18, 6, 1, '2024/2025', 'May 2026', '0000-00-00', 880000.00, NULL, '', 0, 2),
(148, 84, 12, 1, 2, '2025/2026', 'March 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(149, 84, 12, 2, 2, '2025/2026', 'April 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(150, 84, 12, 3, 2, '2025/2026', 'May 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(151, 84, 12, 4, 2, '2025/2026', 'June 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(152, 84, 12, 5, 2, '2025/2026', 'July 2026', '0000-00-00', 800000.00, NULL, '', 0, 2),
(153, 84, 12, 6, 2, '2025/2026', 'August 2026', '0000-00-00', 800000.00, NULL, '', 0, 2);

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`role_id`, `role_name`) VALUES
(2, 'Admin'),
(1, 'Head Admin');

-- --------------------------------------------------------

--
-- Table structure for table `siswa`
--

CREATE TABLE `siswa` (
  `siswa_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `jenis_kelamin` enum('P','L') NOT NULL,
  `tanggal_lahir` date NOT NULL,
  `cabang_id` int(11) NOT NULL,
  `asal_sekolah` varchar(255) NOT NULL,
  `nama_orangtua` varchar(100) DEFAULT NULL,
  `no_telp` varchar(20) DEFAULT NULL,
  `status` enum('aktif','nonaktif','cuti') NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `siswa`
--

INSERT INTO `siswa` (`siswa_id`, `name`, `username`, `password`, `jenis_kelamin`, `tanggal_lahir`, `cabang_id`, `asal_sekolah`, `nama_orangtua`, `no_telp`, `status`, `created_at`) VALUES
(83, 'Dodo', 'dodo123', '$2y$10$mdJtLh4P6tcwKVMvbFfd7..aBn9tToOsQxKWtM2gAojLtlurko47O', 'L', '2012-10-23', 1, 'SMA Santo Yosef', 'Mr. Yow', '082175273486', 'aktif', '2025-11-23 21:02:54'),
(84, 'Cliff Gilbert', 'cliff1234', '$2y$10$bs8khPqzhVolvjYtpBXPbuAHyFvMbYD1T7rhT9OydlKXxkgu3EYzC', 'L', '2012-10-23', 1, 'SMA Santo Yosef', 'Mr. Yow', '082175273486', 'aktif', '2025-12-03 22:48:15'),
(85, 'Veren', 'veren123', '$2y$10$V1AUqddvuC1dH6k2kamfHeOjXi96qHIALNJeaaLEwtn6/vS9Z68ZK', 'P', '2014-10-15', 1, 'SMA Santo Yosef', 'Mr. Yow', '082175273486', 'aktif', '2025-12-04 11:34:36');

-- --------------------------------------------------------

--
-- Table structure for table `siswa_datales`
--

CREATE TABLE `siswa_datales` (
  `id` int(11) NOT NULL,
  `siswa_id` int(11) NOT NULL,
  `datales_id` int(11) NOT NULL,
  `slot_id` int(11) DEFAULT NULL COMMENT 'ID slot jadwal yang diambil',
  `status` enum('aktif','nonaktif','selesai','naik_tingkat','berhenti','lanjut_semester') DEFAULT 'aktif',
  `semester_ke` int(11) NOT NULL DEFAULT 1 COMMENT 'Semester ke berapa saat ini',
  `tahun_ajaran` varchar(20) DEFAULT NULL COMMENT 'Tahun ajaran aktif',
  `bulan_aktif` int(11) NOT NULL DEFAULT 1 COMMENT 'Bulan ke berapa yang sedang berjalan (1-6)',
  `tanggal_mulai_semester` date DEFAULT NULL COMMENT 'Kapan semester ini dimulai',
  `tanggal_selesai_semester` date DEFAULT NULL COMMENT 'Kapan semester ini target selesai',
  `total_pertemuan_semester` int(11) DEFAULT NULL COMMENT 'Total pertemuan dalam 1 semester (6 bulan)',
  `is_history` tinyint(1) DEFAULT 0 COMMENT 'Apakah ini data history (sudah selesai)',
  `tanggal_archived` date DEFAULT NULL COMMENT 'Kapan di-archive',
  `archived_by` int(11) DEFAULT NULL COMMENT 'User yang archive',
  `catatan_history` text DEFAULT NULL COMMENT 'Catatan saat archive',
  `tanggal_mulai` date DEFAULT NULL COMMENT 'Tanggal mulai les'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `siswa_datales`
--

INSERT INTO `siswa_datales` (`id`, `siswa_id`, `datales_id`, `slot_id`, `status`, `semester_ke`, `tahun_ajaran`, `bulan_aktif`, `tanggal_mulai_semester`, `tanggal_selesai_semester`, `total_pertemuan_semester`, `is_history`, `tanggal_archived`, `archived_by`, `catatan_history`, `tanggal_mulai`) VALUES
(95, 83, 14, 13, 'aktif', 1, '2024/2025', 6, '2025-11-26', '2026-05-26', 12, 0, NULL, NULL, NULL, '2025-11-26'),
(97, 85, 18, 16, 'aktif', 1, '2024/2025', 1, '2025-12-11', '2026-06-11', 12, 0, NULL, NULL, NULL, '2025-12-11'),
(98, 84, 12, NULL, 'aktif', 1, NULL, 1, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tipeles`
--

CREATE TABLE `tipeles` (
  `tipeles_id` int(11) NOT NULL,
  `jenisles_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `jumlahpertemuan` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tipeles`
--

INSERT INTO `tipeles` (`tipeles_id`, `jenisles_id`, `name`, `jumlahpertemuan`) VALUES
(8, 6, 'Biola', 2),
(9, 6, 'Piano', 2),
(14, 6, 'Vocal', 2);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role_id`, `full_name`) VALUES
(1, 'headadmin', '$2y$10$azVLH6.upfWqwAfkdZdxe.fjRbrUjTciwLpMBeXqugvEaArIA2.mm', 1, 'Veren'),
(29, 'cliff123', '$2y$10$R79OHJxanESa.KrF0o/42Ot/E0U.vu4UCmorqTplV2vaROcmCugbK', 2, 'Cliff');

-- --------------------------------------------------------

--
-- Table structure for table `user_cabang`
--

CREATE TABLE `user_cabang` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cabang_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_cabang`
--

INSERT INTO `user_cabang` (`id`, `user_id`, `cabang_id`) VALUES
(47, 29, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cabang`
--
ALTER TABLE `cabang`
  ADD PRIMARY KEY (`cabang_id`);

--
-- Indexes for table `cabangguru`
--
ALTER TABLE `cabangguru`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_guru_cabang` (`guru_id`,`cabang_id`),
  ADD KEY `cabang_id` (`cabang_id`);

--
-- Indexes for table `datales`
--
ALTER TABLE `datales`
  ADD PRIMARY KEY (`datales_id`),
  ADD KEY `jenistingkat_id` (`jenistingkat_id`),
  ADD KEY `cabang_id` (`cabang_id`);

--
-- Indexes for table `guru`
--
ALTER TABLE `guru`
  ADD PRIMARY KEY (`guru_id`);

--
-- Indexes for table `guru_datales`
--
ALTER TABLE `guru_datales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_guru_datales_cabang` (`guru_id`,`datales_id`,`cabang_id`),
  ADD KEY `datales_id` (`datales_id`),
  ADD KEY `cabang_id` (`cabang_id`);

--
-- Indexes for table `jadwal_pertemuan`
--
ALTER TABLE `jadwal_pertemuan`
  ADD PRIMARY KEY (`jadwal_id`),
  ADD KEY `idx_pembayaran` (`pembayaran_id`),
  ADD KEY `idx_tanggal` (`tanggal_pertemuan`),
  ADD KEY `idx_guru` (`guru_id`),
  ADD KEY `idx_siswa_datales` (`siswa_id`,`datales_id`),
  ADD KEY `slot_id` (`slot_id`),
  ADD KEY `fk_jadwal_datales` (`datales_id`),
  ADD KEY `idx_bulan` (`siswa_id`,`datales_id`,`bulan_ke`,`semester_ke`);

--
-- Indexes for table `jadwal_slot`
--
ALTER TABLE `jadwal_slot`
  ADD PRIMARY KEY (`slot_id`),
  ADD KEY `cabangguruID` (`cabangguruID`),
  ADD KEY `jenistingkat_id` (`jenistingkat_id`);

--
-- Indexes for table `jadwal_slot_history`
--
ALTER TABLE `jadwal_slot_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `slot_id` (`slot_id`);

--
-- Indexes for table `jenisles`
--
ALTER TABLE `jenisles`
  ADD PRIMARY KEY (`jenisles_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `jenistingkat`
--
ALTER TABLE `jenistingkat`
  ADD PRIMARY KEY (`jenistingkat_id`),
  ADD KEY `tipeles_id` (`tipeles_id`);

--
-- Indexes for table `pembayaran`
--
ALTER TABLE `pembayaran`
  ADD PRIMARY KEY (`pembayaran_id`),
  ADD KEY `datales_id` (`datales_id`),
  ADD KEY `idx_siswa` (`siswa_id`),
  ADD KEY `idx_status` (`status_pembayaran`),
  ADD KEY `idx_bulan_bayar` (`siswa_id`,`datales_id`,`bulan_ke`,`semester_ke`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `siswa`
--
ALTER TABLE `siswa`
  ADD PRIMARY KEY (`siswa_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `cabang_id` (`cabang_id`);

--
-- Indexes for table `siswa_datales`
--
ALTER TABLE `siswa_datales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_siswa_datales` (`siswa_id`,`datales_id`),
  ADD KEY `datales_id` (`datales_id`),
  ADD KEY `fk_siswa_datales_slot` (`slot_id`),
  ADD KEY `idx_history` (`siswa_id`,`is_history`),
  ADD KEY `idx_semester` (`siswa_id`,`semester_ke`,`tahun_ajaran`);

--
-- Indexes for table `tipeles`
--
ALTER TABLE `tipeles`
  ADD PRIMARY KEY (`tipeles_id`),
  ADD KEY `jenisles_id` (`jenisles_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `username_2` (`username`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `user_cabang`
--
ALTER TABLE `user_cabang`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_cabang` (`user_id`,`cabang_id`),
  ADD KEY `cabang_id` (`cabang_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cabang`
--
ALTER TABLE `cabang`
  MODIFY `cabang_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `cabangguru`
--
ALTER TABLE `cabangguru`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `datales`
--
ALTER TABLE `datales`
  MODIFY `datales_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `guru`
--
ALTER TABLE `guru`
  MODIFY `guru_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `guru_datales`
--
ALTER TABLE `guru_datales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `jadwal_pertemuan`
--
ALTER TABLE `jadwal_pertemuan`
  MODIFY `jadwal_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=205;

--
-- AUTO_INCREMENT for table `jadwal_slot`
--
ALTER TABLE `jadwal_slot`
  MODIFY `slot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `jadwal_slot_history`
--
ALTER TABLE `jadwal_slot_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `jenisles`
--
ALTER TABLE `jenisles`
  MODIFY `jenisles_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `jenistingkat`
--
ALTER TABLE `jenistingkat`
  MODIFY `jenistingkat_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `pembayaran`
--
ALTER TABLE `pembayaran`
  MODIFY `pembayaran_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=154;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `siswa`
--
ALTER TABLE `siswa`
  MODIFY `siswa_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `siswa_datales`
--
ALTER TABLE `siswa_datales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `tipeles`
--
ALTER TABLE `tipeles`
  MODIFY `tipeles_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `user_cabang`
--
ALTER TABLE `user_cabang`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cabangguru`
--
ALTER TABLE `cabangguru`
  ADD CONSTRAINT `cabangguru_ibfk_1` FOREIGN KEY (`guru_id`) REFERENCES `guru` (`guru_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cabangguru_ibfk_2` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`cabang_id`) ON DELETE CASCADE;

--
-- Constraints for table `datales`
--
ALTER TABLE `datales`
  ADD CONSTRAINT `fk_datales_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`cabang_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_datales_jenistingkat` FOREIGN KEY (`jenistingkat_id`) REFERENCES `jenistingkat` (`jenistingkat_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `guru_datales`
--
ALTER TABLE `guru_datales`
  ADD CONSTRAINT `guru_datales_ibfk_1` FOREIGN KEY (`guru_id`) REFERENCES `guru` (`guru_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `guru_datales_ibfk_2` FOREIGN KEY (`datales_id`) REFERENCES `datales` (`datales_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `guru_datales_ibfk_3` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`cabang_id`) ON DELETE CASCADE;

--
-- Constraints for table `jadwal_slot`
--
ALTER TABLE `jadwal_slot`
  ADD CONSTRAINT `jadwal_slot_ibfk_1` FOREIGN KEY (`cabangguruID`) REFERENCES `cabangguru` (`id`),
  ADD CONSTRAINT `jadwal_slot_ibfk_2` FOREIGN KEY (`jenistingkat_id`) REFERENCES `jenistingkat` (`jenistingkat_id`);

--
-- Constraints for table `jadwal_slot_history`
--
ALTER TABLE `jadwal_slot_history`
  ADD CONSTRAINT `jadwal_slot_history_ibfk_1` FOREIGN KEY (`slot_id`) REFERENCES `jadwal_slot` (`slot_id`) ON DELETE CASCADE;

--
-- Constraints for table `jenistingkat`
--
ALTER TABLE `jenistingkat`
  ADD CONSTRAINT `fk_jenistingkat_tipeles` FOREIGN KEY (`tipeles_id`) REFERENCES `tipeles` (`tipeles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pembayaran`
--
ALTER TABLE `pembayaran`
  ADD CONSTRAINT `pembayaran_ibfk_1` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`siswa_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pembayaran_ibfk_2` FOREIGN KEY (`datales_id`) REFERENCES `datales` (`datales_id`) ON DELETE CASCADE;

--
-- Constraints for table `siswa`
--
ALTER TABLE `siswa`
  ADD CONSTRAINT `fk_siswa_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`cabang_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `siswa_datales`
--
ALTER TABLE `siswa_datales`
  ADD CONSTRAINT `fk_siswa_datales_slot` FOREIGN KEY (`slot_id`) REFERENCES `jadwal_slot` (`slot_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `siswa_datales_ibfk_1` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`siswa_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `siswa_datales_ibfk_2` FOREIGN KEY (`datales_id`) REFERENCES `datales` (`datales_id`) ON DELETE CASCADE;

--
-- Constraints for table `tipeles`
--
ALTER TABLE `tipeles`
  ADD CONSTRAINT `fk_tipeles_jenisles` FOREIGN KEY (`jenisles_id`) REFERENCES `jenisles` (`jenisles_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_cabang`
--
ALTER TABLE `user_cabang`
  ADD CONSTRAINT `user_cabang_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_cabang_ibfk_2` FOREIGN KEY (`cabang_id`) REFERENCES `cabang` (`cabang_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
