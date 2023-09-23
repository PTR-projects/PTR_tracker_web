-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Wrz 18, 2023 at 09:00 PM
-- Wersja serwera: 5.7.41-cll-lve
-- Wersja PHP: 8.1.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dm66733_rocket_tracker`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `Data`
--

CREATE TABLE `Data` (
  `position_id` int(11) NOT NULL,
  `flight_id` int(11) DEFAULT NULL,
  `object_id` int(11) DEFAULT NULL,
  `datetime` datetime DEFAULT NULL,
  `packet_no` int(11) DEFAULT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `altitude` decimal(10,2) DEFAULT NULL,
  `gnss_sats` int(11) DEFAULT NULL,
  `gnss_fix` tinyint(4) DEFAULT NULL,
  `max_altitude` decimal(10,2) DEFAULT NULL,
  `vbat` decimal(5,2) DEFAULT NULL,
  `raw` text CHARACTER SET utf8 COLLATE utf8_bin
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `Flights`
--

CREATE TABLE `Flights` (
  `flight_id` int(11) NOT NULL,
  `object_id` int(11) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `last_latitude` decimal(9,6) DEFAULT NULL,
  `last_longitude` decimal(9,6) DEFAULT NULL,
  `last_altitude` decimal(10,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `Objects`
--

CREATE TABLE `Objects` (
  `object_id` int(11) NOT NULL,
  `object_name` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `Data`
--
ALTER TABLE `Data`
  ADD PRIMARY KEY (`position_id`),
  ADD KEY `flight_id` (`flight_id`),
  ADD KEY `object_id` (`object_id`);

--
-- Indeksy dla tabeli `Flights`
--
ALTER TABLE `Flights`
  ADD PRIMARY KEY (`flight_id`),
  ADD KEY `object_id` (`object_id`);

--
-- Indeksy dla tabeli `Objects`
--
ALTER TABLE `Objects`
  ADD PRIMARY KEY (`object_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `Data`
--
ALTER TABLE `Data`
  MODIFY `position_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Flights`
--
ALTER TABLE `Flights`
  MODIFY `flight_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Objects`
--
ALTER TABLE `Objects`
  MODIFY `object_id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
