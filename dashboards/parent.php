<?php
// Included by /dashboard.php. Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
// Parent Dashboard Content - Already exists in parent/dashboard.php
// This is a reference file for the unique parent dashboard design
// The actual parent dashboard is located at parent/dashboard.php

// Parent dashboard features:
// - Child overview cards
// - Academic progress monitoring
// - Attendance tracking
// - Assignment status
// - Communication with teachers
// - School announcements

// The parent dashboard uses a pink/purple gradient theme
// and focuses on child monitoring and communication features
?>

<!-- Parent Dashboard is already implemented in parent/dashboard.php -->
<!-- This file serves as a reference for the unique parent dashboard design -->

<!-- Parent Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-heart mr-1.5"></i> Parent Portal</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Parent'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Monitor your child's academic journey.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-users text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick Actions heading -->
<h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>

<!-- Parent Features -->
<!-- 
- Child overview cards with photos and basic info
- Quick access to academic progress
- Attendance monitoring with visual indicators
- Assignment tracking and due dates
- Communication tools (messages, announcements)
- Fee payment status
- School calendar and events
- Parent-teacher meeting scheduling
-->

<!-- Parent Quick Actions -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
    <a href="child_academic.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-pink-100 dark:bg-pink-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-pink-200 dark:group-hover:bg-pink-800 transition-colors duration-200">
            <i class="fas fa-chart-line text-pink-600 dark:text-pink-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Progress</span>
    </a>
    <a href="child_attendance.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
            <i class="fas fa-calendar-check text-purple-600 dark:text-purple-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Attendance</span>
    </a>
    <a href="child_assignments.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
            <i class="fas fa-tasks text-indigo-600 dark:text-indigo-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Assignments</span>
    </a>
    <a href="communication/messages.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
            <i class="fas fa-comments text-blue-600 dark:text-blue-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Messages</span>
    </a>
    <a href="fees.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
            <i class="fas fa-money-bill-wave text-green-600 dark:text-green-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Fee Payment</span>
    </a>
    <a href="calendar.php" class="flex flex-col items-center p-5 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 group">
        <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
            <i class="fas fa-calendar-alt text-teal-600 dark:text-teal-400 text-xl"></i>
        </div>
        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">School Calendar</span>
    </a>
</div>
