-- Create online discussions tables for the school management system

-- Create online_discussions table
CREATE TABLE IF NOT EXISTS `online_discussions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('active','closed','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_subject_id` (`subject_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_discussions_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_discussions_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_discussions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create discussion_posts table
CREATE TABLE IF NOT EXISTS `discussion_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discussion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `parent_post_id` int(11) DEFAULT NULL,
  `is_edited` tinyint(1) DEFAULT 0,
  `edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_discussion_id` (`discussion_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_parent_post_id` (`parent_post_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_posts_discussion` FOREIGN KEY (`discussion_id`) REFERENCES `online_discussions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_parent` FOREIGN KEY (`parent_post_id`) REFERENCES `discussion_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create discussion_attachments table
CREATE TABLE IF NOT EXISTS `discussion_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  CONSTRAINT `fk_attachments_post` FOREIGN KEY (`post_id`) REFERENCES `discussion_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create discussion_participants table (for tracking who can access discussions)
CREATE TABLE IF NOT EXISTS `discussion_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discussion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('participant','moderator') DEFAULT 'participant',
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_discussion_user` (`discussion_id`, `user_id`),
  KEY `idx_discussion_id` (`discussion_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_participants_discussion` FOREIGN KEY (`discussion_id`) REFERENCES `online_discussions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample discussions
INSERT INTO `online_discussions` (`title`, `description`, `class_id`, `subject_id`, `created_by`, `status`) VALUES
('Welcome to Mathematics Discussion', 'This is a general discussion forum for mathematics topics. Feel free to ask questions and share insights.', 1, 1, 1, 'active'),
('Science Project Ideas', 'Share and discuss ideas for upcoming science projects. Collaboration is encouraged!', 1, 3, 1, 'active'),
('English Language Discussion', 'Monthly discussion about language skills and literary analysis.', 2, 2, 1, 'active'),
('Our World Our People', 'Collaborative discussion for social studies topics and current events.', 2, 9, 1, 'active'),
('Creative Arts Showcase', 'Share your creative works and get feedback from peers.', 3, 10, 1, 'active');

-- Insert sample discussion posts
INSERT INTO `discussion_posts` (`discussion_id`, `user_id`, `content`) VALUES
(1, 2, 'Welcome everyone! This is our space to discuss mathematics concepts and help each other with problems.'),
(1, 3, 'Thank you for setting this up! I have a question about quadratic equations.'),
(1, 2, 'Feel free to ask your question about quadratic equations. We are here to help!'),
(2, 2, 'For this term''s science project, I suggest we explore renewable energy sources. What do you think?'),
(2, 4, 'That''s a great idea! I was thinking about solar panel efficiency comparisons.'),
(3, 2, 'This month we are reading "To Kill a Mockingbird". Please share your thoughts on the first three chapters.'),
(4, 2, 'Let''s start with ancient civilizations. Who wants to take on Egyptian history?'),
(5, 2, 'If you need help with any programming language, post your questions here. Include your code if possible.');
