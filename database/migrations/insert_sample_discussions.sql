-- Insert sample discussions data

-- Insert sample discussions
INSERT INTO `online_discussions` (`title`, `description`, `class_id`, `subject_id`, `created_by`, `status`) VALUES
('Welcome to Mathematics Discussion', 'This is a general discussion forum for mathematics topics. Feel free to ask questions and share insights.', 1, 1, 1, 'active'),
('Science Project Ideas', 'Share and discuss ideas for upcoming science projects. Collaboration is encouraged!', 1, 3, 1, 'active'),
('English Language Discussion', 'Monthly discussion about language skills and literary analysis.', 2, 2, 1, 'active'),
('Our World Our People', 'Collaborative discussion for social studies topics and current events.', 2, 9, 1, 'active'),
('Creative Arts Showcase', 'Share your creative works and get feedback from peers.', 3, 10, 1, 'active');

-- Insert sample discussion posts
INSERT INTO `discussion_posts` (`discussion_id`, `user_id`, `content`) VALUES
(1, 1, 'Welcome everyone! This is our space to discuss mathematics concepts and help each other with problems.'),
(1, 1, 'Feel free to ask questions about any math topics you are studying.'),
(2, 1, 'For this term''s science project, I suggest we explore renewable energy sources. What do you think?'),
(2, 1, 'Remember to follow the scientific method in your projects.'),
(3, 1, 'This month we are focusing on improving our English language skills. Please share your thoughts and questions.'),
(4, 1, 'Let''s discuss current events and how they relate to our studies.'),
(5, 1, 'Share your creative works here and provide constructive feedback to your peers.');
