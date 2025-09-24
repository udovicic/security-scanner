# Security scanner tool

## Business requirements:

* I want to have a custom made tool that can be easily expanded with new tests so that I can keep up with the health of my websites. The tools must allow me to curate the lists of sites I own. Once the site is added to the system, I will  be offered the list of existing tests that can be executed on it, and I need to confirm which tests I want to be running on that site and which to ignore. Each test can be inverted (success results are treated as failures and vice versa). Tests are executed in the background with configurable periods (specified in days).

## Acceptance criteria:

* App must feature a CRUD interface for adding websites that should be scanned. Each website should take the following for the input:
  * Name
  * URL
  * Scanning period
  * Config about which test to execute and which to ignore
* Tests are predefined in the codebase, and users must only be able to choose from existing tests. Each test must contain a short description that will be reused to show details about testing and next action to end user
* Tests must be easily coded and added to the system, which makes the available to end use
* App must feature a dashboard showing the results of scans outlining the website names and number of failed and passed URLs
* App must feature a detailed page outlining a detailed result of executed tests and their results. It should feature description from each test to show details about testing and next action to end user

## Technical considerations:

* Database storage will be MySQL
* Language for backend will be PHP 8.4
* No existing frameworks will be used, code will be written from scratch
* Backend code must feature single point of entry
* Frontend will be implemented using tailwind CSS and alpine.js
