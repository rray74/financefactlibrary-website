-- Finance Fact Library — database schema v2
-- Hierarchy: categories > subcategories > subjects > facts (pool per subject)
-- Import via phpMyAdmin in hPanel, or: mysql -u USER -p DBNAME < schema.sql
--
-- NOTE: this replaces the flat categories->facts structure from v1. If you
-- already imported v1 and have real data in it, migrate that data into the
-- new `subjects`/`facts` shape rather than re-running this file as-is —
-- it will fail on the old table definitions still being present.

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_category_slug (category_id, slug),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A subject is a single keyword/search-phrase target, e.g.
-- "2-Year Fixed Mortgage Rates". Its slug is the fact page's URL.
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    intro TEXT,                    -- short standfirst shown above the facts
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE CASCADE,
    INDEX idx_subcategory (subcategory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The fact pool for a subject. Fact pages show the full pool — no
-- randomization or rotation. What actually earns an SEO freshness signal
-- is genuinely updating `value`/`last_updated` when the real-world figure
-- changes, not reshuffling which facts are visible.
--
-- `review_frequency_days` sets how often a fact SHOULD be checked against
-- its source, since different data types go stale at very different rates
-- (a mortgage rate moves weekly; an annual tax allowance barely moves at
-- all). See "Fact update procedure" in README.md and /admin/review.php.
CREATE TABLE IF NOT EXISTS facts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    fact_key VARCHAR(100) NOT NULL UNIQUE,   -- e.g. "uk_base_rate"
    label VARCHAR(255) NOT NULL,             -- e.g. "UK Base Interest Rate"
    value VARCHAR(255) NOT NULL,             -- e.g. "4.25"
    unit VARCHAR(50),                        -- e.g. "%", "£", "years"
    context TEXT,                            -- short note on what this figure means
    source_name VARCHAR(255),                -- e.g. "Bank of England"
    source_url VARCHAR(500),
    last_updated DATE NOT NULL,
    review_frequency_days INT NOT NULL DEFAULT 90,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    INDEX idx_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dormant for now — articles/news are a later-stage concern. Left in place
-- so the placeholder-rendering engine in functions.php still has a home
-- once you're ready to build this out; not linked from navigation yet.
CREATE TABLE IF NOT EXISTS article_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    summary VARCHAR(500),
    body MEDIUMTEXT NOT NULL,
    status ENUM('draft','published') DEFAULT 'draft',
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Seed data — demonstrates the hierarchy with a working example.
-- Real subjects should aim for ~20 facts each; only a handful are
-- seeded per subject here to keep this file readable.
-- ============================================================

INSERT INTO categories (name, slug, description) VALUES
    ('Mortgages', 'mortgages', 'Rates, affordability and borrowing facts'),
    ('Savings', 'savings', 'Interest rates and savings benchmarks'),
    ('Tax', 'tax', 'Allowances, thresholds and rates');

INSERT INTO subcategories (category_id, name, slug, description) VALUES
    (1, 'Fixed Rate Mortgages', 'fixed-rate-mortgages', 'Fixed-term mortgage products'),
    (1, 'Variable Rate Mortgages', 'variable-rate-mortgages', 'Tracker and standard variable rate products'),
    (2, 'ISAs', 'isas', 'Individual Savings Account allowances and rates'),
    (3, 'Income Tax', 'income-tax', 'Personal allowances and rate bands');

INSERT INTO subjects (subcategory_id, name, slug, intro) VALUES
    (1, '2-Year Fixed Mortgage Rates', '2-year-fixed-mortgage-rates',
        'Key figures on 2-year fixed mortgage products across the UK market.'),
    (2, 'Standard Variable Rate (SVR)', 'standard-variable-rate',
        'What lenders standard variable rates look like once an initial deal ends.'),
    (3, 'Cash ISA Allowance', 'cash-isa-allowance',
        'How much you can save tax-free into a Cash ISA this tax year.'),
    (4, 'Income Tax Personal Allowance', 'income-tax-personal-allowance',
        'The amount you can earn before Income Tax applies, and the bands above it.');

INSERT INTO facts (subject_id, fact_key, label, value, unit, context, source_name, source_url, last_updated, review_frequency_days) VALUES
    (1, 'avg_2yr_fixed', 'Average 2-Year Fixed Mortgage Rate', '5.1', '%', 'UK-wide average across residential 2-year fixed products.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01', 7),
    (1, 'lowest_2yr_fixed', 'Lowest Available 2-Year Fixed Rate', '4.29', '%', 'Best-buy rate typically requires a lower loan-to-value.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01', 7),
    (1, 'uk_base_rate', 'Bank of England Base Rate', '4.25', '%', 'Sets the baseline most fixed mortgage pricing is set against.', 'Bank of England', 'https://www.bankofengland.co.uk/monetary-policy/the-interest-rate-bank-rate', '2026-09-01', 42),
    (1, 'avg_product_fee', 'Average Mortgage Product Fee', '999', '£', 'Typical arrangement fee charged on fixed-rate deals.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01', 30),

    (2, 'avg_svr', 'Average Standard Variable Rate', '7.49', '%', 'Rate borrowers revert to once an initial deal period ends.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01', 7),
    (2, 'uk_base_rate_svr', 'Bank of England Base Rate', '4.25', '%', 'SVRs are typically set several points above base rate.', 'Bank of England', 'https://www.bankofengland.co.uk/monetary-policy/the-interest-rate-bank-rate', '2026-09-01', 42),

    (3, 'isa_allowance', 'Annual ISA Allowance', '20000', '£', 'Maximum amount that can be saved tax-free into ISAs per tax year.', 'HMRC', 'https://www.gov.uk/individual-savings-accounts', '2026-04-06', 365),
    (3, 'avg_easy_access_isa', 'Average Easy-Access Cash ISA Rate', '3.15', '%', 'UK-wide average across easy-access Cash ISA products.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01', 14),

    (4, 'personal_allowance', 'Income Tax Personal Allowance', '12570', '£', 'Amount you can earn before paying income tax.', 'HMRC', 'https://www.gov.uk/income-tax-rates', '2026-04-06', 365),
    (4, 'higher_rate_threshold', 'Higher Rate Tax Threshold', '50270', '£', 'Income above this is taxed at the higher rate.', 'HMRC', 'https://www.gov.uk/income-tax-rates', '2026-04-06', 365);
