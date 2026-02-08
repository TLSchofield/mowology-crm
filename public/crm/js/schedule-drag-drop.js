/**
 * Schedule Drag-and-Drop Module
 *
 * Enables drag-and-drop functionality for job cards on the calendar
 * - Drag jobs between days
 * - Drag jobs to different time slots
 * - Updates database via API
 * - Shows visual feedback
 */

(function () {
  'use strict';

  let draggedJob = null;
  let originalDate = null;
  let originalTime = null;

  const feedbackEl = document.getElementById('dragFeedback');
  const feedbackMsg = document.getElementById('dragMessage');

  /**
   * Initialize drag-and-drop event listeners
   */
  function initDragAndDrop() {
    // Get all draggable job cards
    const jobCards = document.querySelectorAll('.mw-job-card-sched');

    jobCards.forEach((card) => {
      card.addEventListener('dragstart', handleDragStart);
      card.addEventListener('dragend', handleDragEnd);
    });

    // Get all drop zones (calendar days)
    const dayContainers = document.querySelectorAll('.mw-day-jobs-container');

    dayContainers.forEach((container) => {
      container.addEventListener('dragover', handleDragOver);
      container.addEventListener('dragleave', handleDragLeave);
      container.addEventListener('drop', handleDrop);
    });

    // Also allow dropping on empty calendar days
    const calendarDays = document.querySelectorAll('.mw-calendar-day');
    calendarDays.forEach((day) => {
      day.addEventListener('dragover', handleDragOver);
      day.addEventListener('dragleave', handleDragLeave);
    });
  }

  /**
   * Handle drag start
   */
  function handleDragStart(e) {
    draggedJob = this;
    originalDate = this.dataset.scheduledDate;
    originalTime = this.dataset.scheduledTime;

    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';

    // Set custom drag image
    const dragImage = new Image();
    dragImage.src =
      'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="50" height="50"%3E%3Ccircle cx="25" cy="25" r="25" fill="%232D8659" opacity="0.8"/%3E%3C/svg%3E';
    e.dataTransfer.setDragImage(dragImage, 25, 25);
  }

  /**
   * Handle drag end
   */
  function handleDragEnd(e) {
    this.classList.remove('dragging');

    // Clear all drag-over states
    document
      .querySelectorAll('.drag-over')
      .forEach((el) => el.classList.remove('drag-over'));
  }

  /**
   * Handle drag over
   */
  function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    // Add visual feedback
    if (this.classList.contains('mw-day-jobs-container')) {
      this.classList.add('drag-over');
    } else if (this.classList.contains('mw-calendar-day')) {
      this.classList.add('drag-over');
    }
  }

  /**
   * Handle drag leave
   */
  function handleDragLeave(e) {
    // Only remove if leaving the actual element, not a child
    if (e.target === this) {
      this.classList.remove('drag-over');
    }
  }

  /**
   * Handle drop
   */
  function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();

    if (!draggedJob) return;

    let targetContainer = this;
    let targetDay = null;

    // If dropped on container, get parent day
    if (this.classList.contains('mw-day-jobs-container')) {
      targetDay = this.closest('.mw-calendar-day');
      targetContainer = this;
    } else if (this.classList.contains('mw-calendar-day')) {
      targetDay = this;
      targetContainer = this.querySelector('.mw-day-jobs-container');
    }

    this.classList.remove('drag-over');

    if (!targetDay) return;

    const newDate = targetDay.dataset.date;

    // If dropped on same date, ask about time change
    if (newDate === originalDate) {
      showFeedback('Job not moved (same day)', 'warning');
      return;
    }

    // Reschedule the job
    rescheduleJob(draggedJob, newDate, null, targetContainer);
  }

  /**
   * Reschedule job via API
   */
  function rescheduleJob(jobCard, newDate, newTime, targetContainer) {
    const jobId = parseInt(jobCard.dataset.jobId);
    const jobNumber = jobCard.dataset.jobNumber;

    // Prepare payload
    const payload = {
      job_id: jobId,
      scheduled_date: newDate,
    };

    if (newTime) {
      payload.scheduled_time_start = newTime;
    }

    // Show loading state
    jobCard.style.opacity = '0.6';
    showFeedback('Updating schedule...', 'loading');

    // Send API request
    fetch('/crm/api/reschedule-job.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    })
      .then((response) => {
        if (!response.ok) {
          return response.json().then((data) => {
            throw new Error(data.error || 'Failed to reschedule job');
          });
        }
        return response.json();
      })
      .then((data) => {
        // Move card to new container
        if (targetContainer && !targetContainer.querySelector('.mw-empty-day')) {
          targetContainer.appendChild(jobCard);
        }

        // Update data attributes
        jobCard.dataset.scheduledDate = newDate;
        jobCard.style.opacity = '1';

        // Show success message
        const dateObj = new Date(newDate);
        const dateStr = dateObj.toLocaleDateString('en-US', {
          weekday: 'short',
          month: 'short',
          day: 'numeric',
        });
        showFeedback(
          `${jobNumber} rescheduled to ${dateStr}`,
          'success'
        );

        draggedJob = null;
      })
      .catch((error) => {
        // Restore original state
        jobCard.style.opacity = '1';
        showFeedback(`Error: ${error.message}`, 'error');
        console.error('Reschedule error:', error);

        draggedJob = null;
      });
  }

  /**
   * Show feedback message
   */
  function showFeedback(message, type = 'info') {
    feedbackMsg.textContent = message;
    feedbackEl.className = 'mw-drag-feedback';

    if (type === 'error') {
      feedbackEl.classList.add('error');
    } else if (type === 'success') {
      feedbackEl.classList.remove('error');
    }

    feedbackEl.style.display = 'block';

    // Auto-hide after 3 seconds (5 seconds for loading)
    const timeout = type === 'loading' ? 10000 : 3000;
    setTimeout(() => {
      feedbackEl.style.display = 'none';
    }, timeout);
  }

  /**
   * Initialize when DOM is ready
   */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDragAndDrop);
  } else {
    initDragAndDrop();
  }

  // Re-initialize if new jobs are added dynamically
  window.reinitScheduleDragDrop = initDragAndDrop;
})();
