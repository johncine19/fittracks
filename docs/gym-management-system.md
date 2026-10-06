# Gym Management System with Personalized Nutrition Module

## I. Introduction

The Gym Management System is a web-based platform designed to streamline the daily operations of a fitness facility while giving members a personalized, data-driven approach to their nutrition and training. Beyond standard administrative functions like membership handling and scheduling, the system's defining feature is its ability to analyze a member's body metrics, activity level, and fitness goals to generate tailored dietary recommendations that adjust automatically as the member's progress data changes.

The system serves four distinct user roles, each with a scope of access suited to their responsibilities: the Admin who oversees the entire platform, Staff who handle front-desk and operational tasks, Coaches who manage training and nutrition guidance for their assigned members, and Users/Customers who are the gym's paying members.

## II. Objectives

**General Objective:** To design and develop a centralized system that manages gym operations and delivers personalized dietary guidance to members based on their physiological data and fitness goals.

**Specific Objectives:**
- Automate membership registration, renewal, and billing processes.
- Provide role-based access control across four distinct user types.
- Generate personalized calorie and macronutrient targets using established metabolic formulas.
- Allow coaches to review and adjust system-generated dietary suggestions for their assigned members.
- Track member progress (weight, measurements, attendance) and use that data to recalibrate recommendations over time.
- Provide administrators with reporting tools for membership trends, revenue, and facility usage.

## III. User Roles and Permissions

### A. Admin
The Admin has full system control and is typically the gym owner or general manager.
- Create, edit, deactivate, or delete Staff and Trainer accounts.
- View and manage all member accounts and subscription records.
- Configure membership plans, pricing, and class offerings.
- Access financial reports, revenue summaries, and attendance analytics.
- Configure system-wide settings, including the parameters used by the dietary suggestion engine (e.g., formula constants, default macro splits).
- Override or audit any record in the system.

### B. Staff
Staff represent front-desk personnel responsible for daily, operational tasks.
- Register new members and process walk-in inquiries.
- Handle check-ins/check-outs and attendance logging.
- Process payments, renewals, and issue receipts.
- Manage class schedules and room/equipment bookings.
- View basic member information needed for front-desk service (contact details, membership status) but not full health/nutrition data.

### C. Trainer
Coaches are assigned to specific members or classes and focus on training and nutrition oversight.
- View detailed profiles of assigned members, including body metrics, goals, and the system's generated diet and workout suggestions.
- Adjust or override automated dietary and training recommendations based on professional judgment.
- Log workout sessions, update training plans, and record progress check-ins (weight, measurements, performance notes).
- Communicate directly with assigned members through in-app messaging or notes.
- Cannot access billing, other coaches' clients, or admin-level settings.

### D. User/Member
The member-facing role, representing paying gym clients.
- Register and maintain a personal profile (height, weight, age, activity level, goals, dietary restrictions/allergies).
- View their own personalized dietary suggestions and macro/calorie targets.
- View and book available classes, and track their own attendance history.
- Log progress data such as weigh-ins, measurements, or meals eaten.
- View assigned trainer's notes and recommendations.
- Manage their own membership/billing status and payment history.

## IV. System Features

### A. Membership Management
Handles registration, plan selection (e.g., monthly, quarterly, annual), renewals, upgrades/downgrades, and cancellations.

### B. Class & Schedule Management
Staff and Admin create class schedules (yoga, strength training, spin, etc.); members browse and book slots, subject to capacity limits.

### C. Attendance Tracking
Check-in via QR code, RFID card, or manual front-desk entry. Attendance feeds into both billing (for usage-based plans) and trainer progress reviews.

### D. Billing & Payments
Tracks invoices, payment methods, due dates, and overdue notices. Generates receipts and supports partial/installment plans if needed.

### E. Trainer Assignment & Training Plans
Admin or Staff assign coaches to members (or members select from available coaches). Coaches build and update structured workout plans tied to each member's profile.

### F. Dietary & Nutrition Suggestion Module
The system's core differentiator, detailed fully in Section V.

### G. Progress Tracking
Members and coaches log recurring data points (weight, body measurements, photos, performance benchmarks). The system visualizes trends over time and feeds this data back into the nutrition module for recalibration.

### H. Notifications
Automated reminders for membership renewal, upcoming classes, trainer messages, and milestones reached (e.g., "You've hit your 3-month weigh-in goal").

## V. Dietary Suggestion Feature (Detailed)

This module is what distinguishes the system from a typical gym CRM, so it's broken out here in detail.

**1. Data Collection**
On profile setup (or update), the member provides: height, weight, age, biological sex, activity level (sedentary to very active), primary goal (fat loss, muscle gain, maintenance, general health), and any dietary restrictions or allergies (vegetarian, vegan, halal, lactose-free, nut allergy, etc.).

**2. Calculation Engine**
- The system calculates Basal Metabolic Rate (BMR) using the Mifflin-St Jeor equation.
- BMR is multiplied by an activity factor to estimate Total Daily Energy Expenditure (TDEE).
- Based on the member's stated goal, a calorie target is set (e.g., a moderate deficit for fat loss, a surplus for muscle gain).
- Macronutrient targets (protein, carbohydrates, fats) are derived from the calorie target using goal-appropriate ratios (e.g., higher protein for muscle gain).

**3. Meal Suggestion Output**
The system filters a food/meal database against the member's restrictions and macro targets to suggest sample meals or meal structures (not rigid meal plans, but flexible templates members can swap within).

**4. Trainer Review Layer**
Suggestions generated by the engine are visible to the assigned trainer, who can adjust calorie targets, swap meal suggestions, or add manual notes before they're finalized for the member. This keeps a human-in-the-loop check on automated output.

**5. Adaptive Recalculation**
As the member logs new weigh-ins or progress data, the system periodically checks actual progress against the expected trajectory and flags whether targets should be adjusted (e.g., weight loss has stalled, suggesting a calorie target review).

## VI. Core Database Entities (High-Level)

- **Users** — shared base table with role discriminator (Admin, Staff, Trainer, Member)
- **MemberProfiles** — height, weight, age, activity level, goal, restrictions
- **Memberships** — plan type, start/end date, status
- **Payments** — amount, date, method, status
- **Classes / Schedules** — class details, time slots, capacity
- **Attendance** — check-in records linked to Users and Classes
- **Coaches** — specialization, assigned members
- **TrainingPlans** — exercises, sets/reps, assigned trainer and member
- **DietPlans** — calculated targets, generated meal suggestions, trainer overrides
- **FoodItems** — nutritional database used for meal suggestions
- **ProgressLogs** — recurring weigh-ins, measurements, photos
- **Notifications** — reminders, alerts, messages

## VII. Suggested Technology Stack

- **Backend:** PHP 8.2 (or Laravel for structure) — consistent with prior coursework experience
- **Database:** MySQL 8.0
- **Frontend:** TailwindCSS + JavaScript
- **Authentication:** Role-based session authentication with password hashing (bcrypt)
- **Optional additions:** Chart.js for progress visualizations, a barcode/QR library for check-ins

## VIII. Use Case Summary by Role

| Role | Primary Use Cases |
|---|---|
| Admin | Manage staff/trainer accounts, configure plans, view reports, audit records |
| Staff | Register members, process payments, manage check-ins, schedule classes |
| Trainer | Review/adjust diet plans, manage training plans, log member progress |
| User/Member | View own diet & training plan, book classes, log progress, manage billing |

## IX. Future Enhancements

- Mobile app companion for check-ins and meal logging on the go.
- Wearable device integration (e.g., syncing steps/heart rate to refine activity level estimates).
- AI chatbot for member nutrition Q&A, layered on top of the existing macro engine.
- Barcode scanning for packaged food logging.
