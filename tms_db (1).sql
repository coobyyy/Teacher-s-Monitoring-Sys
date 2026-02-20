-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 03:31 AM
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
-- Database: `tms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

CREATE TABLE `assessments` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `max_score` decimal(6,2) DEFAULT 100.00,
  `assessment_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessments`
--

INSERT INTO `assessments` (`id`, `subject_id`, `name`, `max_score`, `assessment_date`, `created_at`) VALUES
(2, 6, 'Quiz 1', 100.00, NULL, '2026-02-19 23:35:18'),
(3, 6, 'Quiz 3', 100.00, NULL, '2026-02-19 23:35:18'),
(4, 6, 'Activity 1', 100.00, NULL, '2026-02-19 23:35:18'),
(5, 6, 'Activity 3', 100.00, NULL, NULL),
(6, 6, 'Activity 2', 100.00, NULL, NULL),
(7, 6, 'Project 1', 100.00, NULL, NULL),
(8, 6, 'Project 2', 100.00, NULL, NULL),
(9, 6, 'Quarterly Exam', 100.00, NULL, NULL),
(10, 6, 'Monthly Exam', 100.00, NULL, NULL),
(11, 8, 'Quiz 1', 100.00, NULL, NULL),
(12, 8, 'Activity 2', 100.00, NULL, NULL),
(13, 8, 'Activity 1', 100.00, NULL, NULL),
(14, 8, 'Quiz 3', 100.00, NULL, NULL),
(15, 8, 'Quiz 2', 100.00, NULL, NULL),
(16, 8, 'Activity 3', 100.00, NULL, NULL),
(17, 8, 'Project 1', 100.00, NULL, NULL),
(18, 8, 'Project 2', 100.00, NULL, NULL),
(19, 8, 'Monthly Exam', 100.00, NULL, NULL),
(20, 8, 'Quarterly Exam', 100.00, NULL, NULL),
(21, 6, 'Quiz 2', 100.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `className` varchar(50) NOT NULL,
  `section` varchar(10) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `is_advisory` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `adviser_id` int(11) DEFAULT NULL,
  `last_seating_update` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `teacher_id`, `className`, `section`, `academic_year`, `is_advisory`, `created_at`, `adviser_id`, `last_seating_update`) VALUES
(15, 8, 'STEM', 'A', '2026', 1, '2026-02-09 00:05:15', NULL, NULL),
(16, 9, 'STEM', 'A', '2026', 0, '2026-02-09 00:09:23', NULL, NULL),
(19, 10, 'STEM', 'A', '2026', 0, '2026-02-09 00:31:10', NULL, NULL),
(22, 7, 'HUMSS', 'A', '2026', 0, '2026-02-19 18:58:32', NULL, '2026-02-19 20:14:43'),
(39, 11, 'STEM', 'A', '2026', 1, '2026-02-20 02:08:57', 11, '2026-02-20 02:13:34');

-- --------------------------------------------------------

--
-- Table structure for table `scores`
--

CREATE TABLE `scores` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject` varchar(50) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject_id` int(11) DEFAULT NULL,
  `assessment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `seat_row` int(11) DEFAULT NULL,
  `seat_column` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `name`) VALUES
(6, 'Computer Servicing System (CSS) II'),
(8, 'Contemporary Philippine Arts From The Region'),
(9, 'English for Academic and Professional Purposes'),
(7, 'Research Project');

-- --------------------------------------------------------

--
-- Table structure for table `subject_merge_map`
--

CREATE TABLE `subject_merge_map` (
  `id` int(11) NOT NULL,
  `old_subject_id` int(11) NOT NULL,
  `new_subject_id` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `fullName` varchar(100) NOT NULL,
  `username` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` varchar(20) DEFAULT 'teacher',
  `status` varchar(20) DEFAULT 'pending',
  `email` varchar(255) DEFAULT NULL,
  `contacts` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `subjects` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `fullName`, `username`, `password`, `created_at`, `role`, `status`, `email`, `contacts`, `first_name`, `middle_name`, `last_name`, `subjects`) VALUES
(7, 'Cobie Ignacio', 'adminako', '$2y$10$HFkVSlMipihd7ROBzY5hoePzx/EhmcmSVNsNwxNTBkoeBDYvMFPMi', '2026-02-08 23:59:14', 'admin', 'active', 'cbignacio03@gmail.com', '', NULL, NULL, NULL, NULL),
(8, 'Jeffrey Balagbag', 'jipri12345', '$2y$10$6BXAK/0zO4ngPUf07EGoeu./KXusI/nKSAZff8yym52WZJBWpxXf2', '2026-02-09 00:02:14', 'adviser', 'active', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'Maureen', 'maumau123', '$2y$10$2lWhcAwi/XKaIfOmVEYe6OY7rPhjt5so2FGD/tgH2RavG478qoZwy', '2026-02-09 00:08:56', 'teacher', 'active', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'Steve Fox', 'steve123', '$2y$10$ZDhOKSimEAUH5NpG/Zc0lOgFwMC2wEJrD.SwCO073zz3D7xCeljga', '2026-02-09 00:30:38', 'teacher', 'active', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'Mike Justin', 'mikey01', '$2y$10$tHFCUdZMjr41H9HkKlCAae2wn3OJVKY/PF69AMLzLseBQ6oxZcLU.', '2026-02-19 17:41:42', 'teacher', 'active', 'mikey01@gmail.com', '', NULL, NULL, NULL, NULL),
(12, 'Jeffrey haha', 'jeffrey01', '$2y$10$WTgKyOGJuTDd1udz6yD2cuabPjJScGe2ZdzsVuCgGtLEt/AI.E67a', '2026-02-19 20:17:15', 'teacher', 'active', 'jeffrey01@gmail.com', '', NULL, NULL, NULL, NULL),
(13, 'Cobie Ignacio', 'cobix01', '$2y$10$TpAvZowioSBwNM7VCA.EnOW5DmQJ4UBzOFua1DC0SQcgKZ0hbNaIy', '2026-02-20 02:17:44', 'teacher', 'active', 'cbignacio03@gmail.com', NULL, 'Cobie', '', 'Ignacio', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

CREATE TABLE `teacher_subjects` (
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teacher_subjects`
--

INSERT INTO `teacher_subjects` (`teacher_id`, `subject_id`) VALUES
(11, 8),
(12, 6);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assessments`
--
ALTER TABLE `assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `scores`
--
ALTER TABLE `scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `idx_scores_subject_id` (`subject_id`),
  ADD KEY `idx_scores_assessment_id` (`assessment_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `subject_merge_map`
--
ALTER TABLE `subject_merge_map`
  ADD PRIMARY KEY (`id`),
  ADD KEY `old_subject_id` (`old_subject_id`),
  ADD KEY `new_subject_id` (`new_subject_id`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`teacher_id`,`subject_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assessments`
--
ALTER TABLE `assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `scores`
--
ALTER TABLE `scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `subject_merge_map`
--
ALTER TABLE `subject_merge_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assessments`
--
ALTER TABLE `assessments`
  ADD CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`);

--
-- Constraints for table `scores`
--
ALTER TABLE `scores`
  ADD CONSTRAINT `fk_scores_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_scores_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`);

--
-- Constraints for table `subject_merge_map`
--
ALTER TABLE `subject_merge_map`
  ADD CONSTRAINT `subject_merge_map_ibfk_1` FOREIGN KEY (`old_subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subject_merge_map_ibfk_2` FOREIGN KEY (`new_subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
