// config.js - Easy Configuration File
// Update these values to customize your pre-launch site

const CONFIG = {
    // SITE INFO
    siteName: "StressReleasor",
    siteURL: "https://stressreleasor.com",
    supportEmail: "support@stressreleasor.com",
    
    // LAUNCH SETTINGS
    launchDate: {
        daysFromNow: 30,  // Launch in X days from today
        // OR set specific date (uncomment to use):
        // specificDate: "2025-02-15" // Format: YYYY-MM-DD
    },
    
    // PRICING
    pricing: {
        single: {
            price: 6.99,
            currency: "$"
        },
        monthly: {
            price: 17.99,
            currency: "$"
        },
        yearly: {
            price: 120,
            currency: "$",
            savingsAmount: 95.88
        }
    },
    
    // LAUNCH OFFERS
    launchDiscount: {
        enabled: true,
        percentage: 50,
        limitedSpots: 100,
        showSpotCounter: false
    },
    
    // EMAIL INTEGRATION
    emailService: {
        // Options: "php", "mailchimp", "convertkit", "custom"
        provider: "php",
        
        // Mailchimp Settings (if using)
        mailchimp: {
            apiKey: "YOUR_MAILCHIMP_API_KEY",
            listId: "YOUR_LIST_ID",
            dataCenter: "us1"
        },
        
        // ConvertKit Settings (if using)
        convertkit: {
            apiKey: "YOUR_CONVERTKIT_API_KEY",
            formId: "YOUR_FORM_ID"
        },
        
        // PHP Backend (if using)
        php: {
            endpoint: "/api/subscribe.php"
        }
    },
    
    // SOCIAL MEDIA
    social: {
        facebook: "",
        twitter: "",
        instagram: "",
        linkedin: "",
        youtube: ""
    },
    
    // ANALYTICS
    analytics: {
        googleAnalyticsId: "", // Format: G-XXXXXXXXXX
        facebookPixelId: "",
        enableTracking: false
    },
    
    // FEATURES
    features: {
        showTestimonials: false, // Show testimonials section
        enableLiveChat: false,   // Add live chat widget
        showBlogLink: false,     // Show link to blog
        enableReferrals: false   // Enable referral program
    },
    
    // ASSESSMENT QUIZ
    assessment: {
        numberOfQuestions: 5,
        enableEmailCapture: true,
        showResultsImmediately: true
    },
    
    // ABOUT SECTION
    founder: {
        name: "Your Name",
        title: "Certified Clinical Hypnotherapist",
        bio: "Founder of 247 Online Therapy",
        credentials: [
            "Certified Clinical Hypnotherapist",
            "Founder of 247 Online Therapy",
            "Specialized in stress & anxiety treatment",
            "Evidence-based therapeutic approaches"
        ],
        photoURL: "" // Leave empty to use placeholder
    },
    
    // WAITLIST BENEFITS
    waitlistBenefits: [
        {
            icon: "🎁",
            text: "50% off founding member pricing"
        },
        {
            icon: "📘",
            text: 'Free "7 Instant Stress Relief Techniques" guide'
        },
        {
            icon: "⚡",
            text: "First access when we launch"
        }
    ],
    
    // HERO SECTION STATS
    heroStats: [
        { number: "5 min", label: "Average Session" },
        { number: "$6.99", label: "Single Session" },
        { number: "24/7", label: "Available" }
    ]
};

// Don't modify below this line unless you know what you're doing
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CONFIG;
}
