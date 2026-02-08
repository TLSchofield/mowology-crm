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
      // Also listen for drag events on the card itself (so they bubble to parent slot)
      card.addEventListener('dragover', handleDragOver);
      card.addEventListener('dragleave', handleDragLeave);
      card.addEventListener('drop', handleDrop);
    });

    // Get all drop zones (time slots in hourly grid)
    const timeSlots = document.querySelectorAll('.mw-time-slot');

    timeSlots.forEach((slot) => {
      slot.addEventListener('dragover', handleDragOver);
      slot.addEventListener('dragleave', handleDragLeave);
      slot.addEventListener('drop', handleDrop);
    });

    // Also allow dropping on day containers (for other calendar views)
    const dayContainers = document.querySelectorAll('.mw-day-jobs-container');

    dayContainers.forEach((container) => {
      container.addEventListener('dragover', handleDragOver);
      container.addEventListener('dragleave', handleDragLeave);
      container.addEventListener('drop', handleDrop);
    });

    // And on calendar days
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
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';

    // Add visual feedback - handle both the slot and items inside it
    let target = this;

    // If this is a job card inside a time slot, bubble up to the slot
    if (!target.classList.contains('mw-time-slot') &&
        !target.classList.contains('mw-day-jobs-container') &&
        !target.classList.contains('mw-calendar-day')) {
      target = target.closest('.mw-time-slot') ||
               target.closest('.mw-day-jobs-container') ||
               target.closest('.mw-calendar-day');
    }

    if (target) {
      target.classList.add('drag-over');
    }
  }

  /**
   * Handle drag leave
   */
  function handleDragLeave(e) {
    // Determine the actual drop zone (in case this is a child element)
    let target = this;
    if (!target.classList.contains('mw-time-slot') &&
        !target.classList.contains('mw-day-jobs-container') &&
        !target.classList.contains('mw-calendar-day')) {
      target = target.closest('.mw-time-slot') ||
               target.closest('.mw-day-jobs-container') ||
               target.closest('.mw-calendar-day');
    }

    if (!target) return;

    // Check if we're leaving this element (not just leaving a child)
    // For time slots, check if the relatedTarget is outside
    const rect = target.getBoundingClientRect();
    const x = e.clientX;
    const y = e.clientY;

    const isOutside = (x < rect.left || x >= rect.right || y < rect.top || y >= rect.bottom);

    if (isOutside) {
      target.classList.remove('drag-over');
    }
  }

  /**
   * Handle drop
   */
  function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();

    if (!draggedJob) return;

    let newDate = null;
    let newTime = null;
    let dropZone = this;

    // Find the actual drop zone (time slot, day container, or calendar day)
    if (!dropZone.classList.contains('mw-time-slot') &&
        !dropZone.classList.contains('mw-day-jobs-container') &&
        !dropZone.classList.contains('mw-calendar-day')) {
      // We might have dropped on a child element, bubble up
      dropZone = dropZone.closest('.mw-time-slot') ||
                 dropZone.closest('.mw-day-jobs-container') ||
                 dropZone.closest('.mw-calendar-day');
    }

    if (!dropZone) return;

    // If dropped on a time slot (hourly grid view)
    if (dropZone.classList.contains('mw-time-slot')) {
      newDate = dropZone.dataset.date;
      const hour = parseInt(dropZone.dataset.hour, 10);
      console.log('Dropped on time slot:', {
        'data-date': dropZone.dataset.date,
        'data-hour': dropZone.dataset.hour,
        'parsed hour': hour,
        newDate,
        newTime: hour >= 0 ? `${String(hour).padStart(2, '0')}:00:00` : 'undefined'
      });
      if (hour >= 0) {
        newTime = `${String(hour).padStart(2, '0')}:00:00`;
      }
      dropZone.classList.remove('drag-over');
    }
    // If dropped on container (day view), get parent day
    else if (dropZone.classList.contains('mw-day-jobs-container')) {
      const targetDay = dropZone.closest('.mw-calendar-day');
      if (!targetDay) return;
      newDate = targetDay.dataset.date;
      dropZone.classList.remove('drag-over');
    }
    // If dropped on calendar day directly
    else if (dropZone.classList.contains('mw-calendar-day')) {
      newDate = dropZone.dataset.date;
      dropZone.classList.remove('drag-over');
    }

    if (!newDate) return;

    // If dropped on same date and time, don't reschedule
    if (newDate === originalDate && (!newTime || newTime === originalTime)) {
      showFeedback('Job not moved (same slot)', 'warning');
      return;
    }

    // Reschedule the job
    rescheduleJob(draggedJob, newDate, newTime);
  }

  /**
   * Reschedule job via API
   */
  function rescheduleJob(jobCard, newDate, newTime) {
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

    // Validate payload before sending
    if (!newDate || !newDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
      showFeedback('Invalid date format: "' + newDate + '"', 'validation');
      console.error('Date validation failed:', { newDate, jobId, jobNumber });
      return;
    }

    if (newTime && !newTime.match(/^\d{2}:\d{2}:\d{2}$/)) {
      showFeedback('Invalid time format: "' + newTime + '"', 'validation');
      console.error('Time validation failed:', { newTime, jobId, jobNumber });
      return;
    }

    // Show loading state
    jobCard.style.opacity = '0.6';
    showFeedback('Updating schedule...', 'loading');

    // Log the payload being sent for debugging
    console.log('Sending reschedule request:', { payload, jobId, jobNumber, newDate, newTime });

    // Send API request (using simplified version for debugging)
    fetch('/crm/api/reschedule-job-simple.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    })
      .then((response) => {
        if (!response.ok) {
          return response.json().then((data) => {
            const errorMsg = data.error || 'Failed to reschedule job';
            console.error('API error response:', { status: response.status, data });
            throw new Error(errorMsg);
          }).catch((parseError) => {
            console.error('Failed to parse error response:', parseError);
            throw new Error('Server error: ' + response.status);
          });
        }
        return response.json();
      })
      .then((data) => {
        console.log('Reschedule successful:', data);

        // Update data attributes
        jobCard.dataset.scheduledDate = newDate;
        if (newTime) {
          jobCard.dataset.scheduledTime = newTime;
        }
        jobCard.style.opacity = '1';

        // Show success message
        const dateObj = new Date(newDate);
        const dateStr = dateObj.toLocaleDateString('en-US', {
          weekday: 'short',
          month: 'short',
          day: 'numeric',
        });
        const timeStr = newTime ? ` at ${newTime.substring(0, 5)}` : '';
        showFeedback(
          `${jobNumber} rescheduled to ${dateStr}${timeStr}`,
          'success'
        );

        // Refresh the page after 2 seconds to show the job in its new location
        setTimeout(() => {
          location.reload();
        }, 2000);

        draggedJob = null;
      })
      .catch((error) => {
        // Restore original state
        jobCard.style.opacity = '1';
        const errorMsg = error.message || 'Failed to reschedule job';
        showFeedback(`API Error: ${errorMsg}`, 'error');
        console.error('Reschedule error:', error);
        console.error('Full error:', error);

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

    // Auto-hide timing: errors and validation messages stay longer for analysis
    let timeout;
    if (type === 'error' || type === 'validation') {
      timeout = 30000; // 30 seconds for errors and validation issues
    } else if (type === 'loading') {
      timeout = 10000; // 10 seconds for loading
    } else if (type === 'success') {
      timeout = 5000; // 5 seconds for success
    } else {
      timeout = 3000; // 3 seconds default
    }

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
