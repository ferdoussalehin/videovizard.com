function submitWaitlist(event) {
    event.preventDefault();
    
    const name = document.getElementById('waitlistName').value;
    const email = document.getElementById('waitlistEmail').value;
    const submitButton = event.target.querySelector('button[type="submit"]');
    
    // Disable button and show loading
    submitButton.disabled = true;
    submitButton.textContent = 'Joining...';
    
    // Prepare data
    const formData = new FormData();
    formData.append('name', name);
    formData.append('email', email);
    formData.append('source', 'waitlist');
    
    // Submit to backend
    fetch('submit-waitlist.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Log the response for debugging
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            document.getElementById('waitlistForm').style.display = 'none';
            document.getElementById('waitlistSuccess').style.display = 'block';
        } else {
            // Show the actual error message
            alert('Error: ' + data.message + '\n\nPlease check the browser console for more details.');
            console.error('Full error details:', data);
            submitButton.disabled = false;
            submitButton.textContent = 'Claim My 3 Months FREE ($42 Value)';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Network error. Please check:\n1. Is submit-waitlist.php in the same folder as index.html?\n2. Are you running on a server (not just opening the file)?\n\nError: ' + error.message);
        submitButton.disabled = false;
        submitButton.textContent = 'Claim My 3 Months FREE ($42 Value)';
    });
}

function submitAssessmentEmail(event) {
    event.preventDefault();
    
    const name = document.getElementById('assessmentName').value;
    const email = document.getElementById('assessmentEmail').value;
    const submitButton = event.target.querySelector('button[type="submit"]');
    
    // Disable button and show loading
    submitButton.disabled = true;
    submitButton.textContent = 'Submitting...';
    
    // Prepare data
    const formData = new FormData();
    formData.append('name', name);
    formData.append('email', email);
    formData.append('source', 'assessment');
    formData.append('assessment_data', JSON.stringify(assessmentAnswers));
    
    // Submit to backend
    fetch('submit-waitlist.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            showStep('assessmentThankYou');
        } else {
            alert('Error: ' + data.message + '\n\nPlease check the browser console for more details.');
            console.error('Full error details:', data);
            submitButton.disabled = false;
            submitButton.textContent = 'Get My Report + 3 Months FREE';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Network error: ' + error.message);
        submitButton.disabled = false;
        submitButton.textContent = 'Get My Report + 3 Months FREE';
    });
}