<?php
// Default curated subject taxonomy -- seed data only, used exactly once to
// populate the `subjects` DB table on first run (see ensure_subjects_table()
// in functions.php). After that first migration, this file is never read
// again; the live source of truth is the DB, editable from the admin
// subjects screen (subjects_admin.php) without needing a code deploy. Kept
// here (rather than deleted after migration) so a fresh install always has
// somewhere to seed from, and so the starting taxonomy is reviewable in git.
//
// Each subject has a 'parent' category used to group the browse sidebar
// (see get_grouped_subjects() in functions.php) — purely a display grouping,
// not a separate tag.
//
// Actual item tagging is NOT limited to this list:
//  - arXiv items are additionally tagged with the paper's own declared
//    category codes (e.g. cs.LG, astro-ph.CO, q-bio.NC — arXiv's real
//    ~155-category taxonomy), pulled straight from the API response.
//  - classify_subjects() matches keywords from every subject below against
//    every item regardless of which subject's query found it, so an item
//    can pick up several tags.
//  - Crawled items (from seed_urls) get whatever subject classify_subjects()
//    finds, with no fixed ceiling — new tags are created on the fly by
//    resolve_tag_ids() the same way manual tags always were.
//
// Keyword-list rule of thumb (learned the hard way -- see git history for
// the "gut microbiota tagged Law" incident): avoid bare common English
// words as keywords ("law", "regulation", "design", "art", "music" alone).
// classify_subjects() does word-boundary matching, not substring, but a
// generic *word* still shows up in unrelated writing constantly ("study
// design", "art of war", "laws of thermodynamics"). Prefer specific
// compound phrases instead.

return [
    // Mathematics & Physical Sciences
    'mathematics' => ['label' => 'Mathematics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['mathematics', 'topology', 'combinatorics', 'algebra', 'number theory', 'differential equations']],
    'physics' => ['label' => 'Physics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['physics', 'quantum', 'particle physics', 'astrophysics', 'cosmology', 'relativity', 'condensed matter']],
    'chemistry' => ['label' => 'Chemistry', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['chemistry', 'catalysis', 'organic synthesis', 'chemical reaction']],
    'astronomy' => ['label' => 'Astronomy & Space', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['astronomy', 'exoplanet', 'galaxy', 'telescope', 'space mission']],
    'earth-science' => ['label' => 'Earth Science', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['geology', 'seismology', 'volcanology', 'geophysics', 'earth science']],
    'statistics' => ['label' => 'Statistics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['statistics', 'statistical inference', 'bayesian', 'data analysis']],
    'optics' => ['label' => 'Optics & Photonics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['optics', 'photonics', 'laser physics']],
    'meteorology' => ['label' => 'Meteorology', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['meteorology', 'atmospheric science', 'weather forecasting']],
    'oceanography' => ['label' => 'Oceanography', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['oceanography', 'marine geology', 'ocean circulation']],
    'nuclear-physics' => ['label' => 'Nuclear Physics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['nuclear physics', 'particle accelerator', 'nuclear fission', 'nuclear fusion']],

    // Computing & Engineering
    'artificial-intelligence' => ['label' => 'Artificial Intelligence', 'parent' => 'Computing & Engineering', 'keywords' => ['artificial intelligence', 'machine learning', 'neural network', 'deep learning', 'large language model', 'reinforcement learning']],
    'computer-science' => ['label' => 'Computer Science', 'parent' => 'Computing & Engineering', 'keywords' => ['algorithm', 'computer science', 'distributed systems', 'programming languages', 'cryptography', 'databases', 'software engineering']],
    'robotics' => ['label' => 'Robotics', 'parent' => 'Computing & Engineering', 'keywords' => ['robotics', 'autonomous vehicle', 'robot manipulation', 'drone']],
    'engineering' => ['label' => 'Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['mechanical engineering', 'civil engineering', 'aerospace engineering', 'structural engineering']],
    'materials-science' => ['label' => 'Materials Science', 'parent' => 'Computing & Engineering', 'keywords' => ['materials science', 'nanomaterial', 'polymer', 'semiconductor', 'alloy', 'nanotechnology']],
    'cybersecurity' => ['label' => 'Cybersecurity', 'parent' => 'Computing & Engineering', 'keywords' => ['cybersecurity', 'network security', 'malware analysis', 'penetration testing']],
    'data-science' => ['label' => 'Data Science', 'parent' => 'Computing & Engineering', 'keywords' => ['data science', 'data mining', 'predictive modeling']],
    'human-computer-interaction' => ['label' => 'Human-Computer Interaction', 'parent' => 'Computing & Engineering', 'keywords' => ['human-computer interaction', 'user experience design', 'usability study']],
    'electrical-engineering' => ['label' => 'Electrical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['electrical engineering', 'circuit design', 'power systems engineering']],
    'chemical-engineering' => ['label' => 'Chemical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['chemical engineering', 'process engineering', 'reaction engineering']],
    'biomedical-engineering' => ['label' => 'Biomedical Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['biomedical engineering', 'medical device design', 'tissue engineering']],
    'telecommunications' => ['label' => 'Telecommunications', 'parent' => 'Computing & Engineering', 'keywords' => ['telecommunications', 'wireless communication', '5g network']],

    // Life Sciences & Medicine
    'biology' => ['label' => 'Biology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['biology', 'gene', 'genome', 'cell biology', 'protein', 'evolutionary biology', 'microbiology']],
    'medicine' => ['label' => 'Medicine & Health', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['medicine', 'clinical trial', 'disease', 'treatment', 'diagnosis', 'public health', 'epidemiology']],
    'neuroscience' => ['label' => 'Neuroscience', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['neuroscience', 'brain', 'cognitive', 'neuron', 'neural circuit']],
    'genetics' => ['label' => 'Genetics & Genomics', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['genetics', 'genomics', 'crispr', 'gene therapy', 'genetic variation']],
    'ecology' => ['label' => 'Ecology & Environment', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['ecology', 'biodiversity', 'ecosystem', 'conservation', 'species']],
    'pharmacology' => ['label' => 'Pharmacology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['pharmacology', 'drug development', 'pharmacokinetics']],
    'immunology' => ['label' => 'Immunology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['immunology', 'immune response', 'vaccine development', 'autoimmune disease']],
    'toxicology' => ['label' => 'Toxicology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['toxicology', 'toxicity assessment']],
    'zoology' => ['label' => 'Zoology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['zoology', 'animal behavior', 'wildlife biology']],
    'botany' => ['label' => 'Botany', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['botany', 'plant biology', 'plant physiology']],
    'marine-biology' => ['label' => 'Marine Biology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['marine biology', 'marine ecosystem', 'coral reef']],
    'nursing' => ['label' => 'Nursing', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['nursing care', 'nursing practice', 'patient care']],
    'veterinary-science' => ['label' => 'Veterinary Science', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['veterinary medicine', 'veterinary science', 'animal health']],
    'dentistry' => ['label' => 'Dentistry', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['dentistry', 'dental medicine', 'oral health']],
    'nutrition' => ['label' => 'Nutrition', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['nutrition science', 'dietary intake', 'nutritional epidemiology']],

    // Environment & Energy
    'climate-science' => ['label' => 'Climate Science', 'parent' => 'Environment & Energy', 'keywords' => ['climate change', 'global warming', 'climate model', 'sea level']],
    'energy' => ['label' => 'Energy', 'parent' => 'Environment & Energy', 'keywords' => ['renewable energy', 'solar power', 'battery technology', 'nuclear energy', 'energy storage', 'power grid']],
    'agriculture' => ['label' => 'Agriculture & Food Science', 'parent' => 'Environment & Energy', 'keywords' => ['agriculture', 'crop science', 'food science', 'farming']],
    'environmental-science' => ['label' => 'Environmental Science', 'parent' => 'Environment & Energy', 'keywords' => ['environmental science', 'pollution control', 'environmental policy']],
    'water-resources' => ['label' => 'Water Resources', 'parent' => 'Environment & Energy', 'keywords' => ['water resources', 'hydrology', 'water management']],
    'forestry' => ['label' => 'Forestry', 'parent' => 'Environment & Energy', 'keywords' => ['forestry', 'forest management', 'silviculture']],
    'sustainability' => ['label' => 'Sustainability', 'parent' => 'Environment & Energy', 'keywords' => ['sustainability', 'sustainable development', 'circular economy']],

    // Social Sciences
    'economics' => ['label' => 'Economics', 'parent' => 'Social Sciences', 'keywords' => ['economics', 'econometrics', 'macroeconomic', 'trade policy']],
    'finance' => ['label' => 'Finance', 'parent' => 'Social Sciences', 'keywords' => ['finance', 'asset pricing', 'financial market', 'investment strategy', 'risk management']],
    'psychology' => ['label' => 'Psychology', 'parent' => 'Social Sciences', 'keywords' => ['psychology', 'behavioral science', 'mental health', 'cognitive behavior']],
    'sociology' => ['label' => 'Sociology', 'parent' => 'Social Sciences', 'keywords' => ['sociology', 'social inequality', 'social network analysis', 'demography']],
    'political-science' => ['label' => 'Political Science', 'parent' => 'Social Sciences', 'keywords' => ['political science', 'governance', 'public policy', 'international relations']],
    'anthropology' => ['label' => 'Anthropology', 'parent' => 'Social Sciences', 'keywords' => ['anthropology', 'ethnography', 'cultural studies']],
    'urban-planning' => ['label' => 'Urban Planning', 'parent' => 'Social Sciences', 'keywords' => ['urban planning', 'city planning', 'transportation planning', 'housing policy']],
    'criminology' => ['label' => 'Criminology', 'parent' => 'Social Sciences', 'keywords' => ['criminology', 'crime prevention', 'criminal justice']],
    'gender-studies' => ['label' => 'Gender Studies', 'parent' => 'Social Sciences', 'keywords' => ['gender studies', 'feminist theory', 'gender equality']],
    'human-geography' => ['label' => 'Human Geography', 'parent' => 'Social Sciences', 'keywords' => ['human geography', 'spatial analysis', 'population geography']],
    'public-administration' => ['label' => 'Public Administration', 'parent' => 'Social Sciences', 'keywords' => ['public administration', 'civil service reform']],
    'international-development' => ['label' => 'International Development', 'parent' => 'Social Sciences', 'keywords' => ['international development', 'humanitarian aid', 'development economics']],

    // Humanities & Law
    // 'regulation' and bare 'law' used to be here but are too generic —
    // "iron regulation", "gene regulation", "laws of thermodynamics" etc.
    // are everyday phrasing in science writing with nothing to do with the
    // legal system, and a bare substring/word match on either false-positive
    // tagged unrelated science articles as Law. Kept to legally-specific
    // compound phrases instead.
    'law' => ['label' => 'Law', 'parent' => 'Humanities & Law', 'keywords' => ['legal', 'legislation', 'jurisprudence', 'case law', 'statute', 'regulatory compliance', 'government regulation']],
    'education' => ['label' => 'Education', 'parent' => 'Humanities & Law', 'keywords' => ['education', 'pedagogy', 'learning outcomes', 'curriculum']],
    'dissertations-theses' => ['label' => 'Dissertations & Theses', 'parent' => 'Humanities & Law', 'keywords' => ['dissertation', 'thesis', 'theses', 'doctoral thesis', 'phd thesis']],
    'linguistics' => ['label' => 'Linguistics', 'parent' => 'Humanities & Law', 'keywords' => ['linguistics', 'natural language', 'phonetics', 'syntax']],
    'history' => ['label' => 'History', 'parent' => 'Humanities & Law', 'keywords' => ['history', 'historical', 'archaeology']],
    'philosophy' => ['label' => 'Philosophy', 'parent' => 'Humanities & Law', 'keywords' => ['philosophy', 'ethics', 'epistemology', 'metaphysics']],
    'religious-studies' => ['label' => 'Religious Studies', 'parent' => 'Humanities & Law', 'keywords' => ['religious studies', 'theology', 'comparative religion']],
    'literature' => ['label' => 'Literature', 'parent' => 'Humanities & Law', 'keywords' => ['literary criticism', 'literary theory', 'comparative literature']],
    'classics' => ['label' => 'Classics', 'parent' => 'Humanities & Law', 'keywords' => ['classical studies', 'ancient greek literature', 'latin literature']],
    'library-science' => ['label' => 'Library & Information Science', 'parent' => 'Humanities & Law', 'keywords' => ['library science', 'information science', 'archival studies']],
    'journalism' => ['label' => 'Journalism & Media', 'parent' => 'Humanities & Law', 'keywords' => ['journalism', 'media studies', 'broadcast journalism']],
    'communication-studies' => ['label' => 'Communication Studies', 'parent' => 'Humanities & Law', 'keywords' => ['communication studies', 'mass communication', 'public relations']],

    // Business
    'business' => ['label' => 'Business & Management', 'parent' => 'Business', 'keywords' => ['business management', 'organizational behavior', 'supply chain', 'marketing strategy']],
    'accounting' => ['label' => 'Accounting', 'parent' => 'Business', 'keywords' => ['accounting', 'auditing', 'financial reporting']],
    'entrepreneurship' => ['label' => 'Entrepreneurship', 'parent' => 'Business', 'keywords' => ['entrepreneurship', 'startup strategy', 'venture capital']],
    'human-resources' => ['label' => 'Human Resources', 'parent' => 'Business', 'keywords' => ['human resource management', 'talent management', 'workplace culture']],
    'operations-management' => ['label' => 'Operations Management', 'parent' => 'Business', 'keywords' => ['operations management', 'logistics management', 'supply chain optimization']],
    'real-estate' => ['label' => 'Real Estate', 'parent' => 'Business', 'keywords' => ['real estate investment', 'property management']],

    // Arts & Design
    'visual-arts' => ['label' => 'Visual Arts', 'parent' => 'Arts & Design', 'keywords' => ['visual arts', 'painting technique', 'sculpture', 'contemporary art']],
    'design' => ['label' => 'Design', 'parent' => 'Arts & Design', 'keywords' => ['graphic design', 'industrial design', 'interior design', 'design thinking']],
    'architecture' => ['label' => 'Architecture', 'parent' => 'Arts & Design', 'keywords' => ['architecture', 'architectural design', 'urban design']],
    'performing-arts' => ['label' => 'Performing Arts', 'parent' => 'Arts & Design', 'keywords' => ['performing arts', 'theatre studies', 'dance studies']],
    'musicology' => ['label' => 'Music & Musicology', 'parent' => 'Arts & Design', 'keywords' => ['musicology', 'music theory', 'ethnomusicology']],
    'film-studies' => ['label' => 'Film Studies', 'parent' => 'Arts & Design', 'keywords' => ['film studies', 'cinema studies', 'screenwriting']],

    // Sports & Recreation
    'sports-science' => ['label' => 'Sports Science', 'parent' => 'Sports & Recreation', 'keywords' => ['sports science', 'exercise physiology', 'sports medicine']],
    'kinesiology' => ['label' => 'Kinesiology', 'parent' => 'Sports & Recreation', 'keywords' => ['kinesiology', 'biomechanics', 'motor control']],
    'tourism' => ['label' => 'Tourism & Hospitality', 'parent' => 'Sports & Recreation', 'keywords' => ['tourism management', 'hospitality management', 'travel industry']],

    // Military & Defense
    'military-studies' => ['label' => 'Military & Defense Studies', 'parent' => 'Military & Defense', 'keywords' => ['military strategy', 'defense policy', 'national security']],

    // Fallback only -- deliberately empty keywords, so classify_subjects()
    // never matches it via the normal keyword loop. insert_item_if_new()
    // (functions.php) applies it directly whenever every other source
    // (source-provided category, seed subject, keyword match) came back
    // with nothing, so no item is ever left with zero tags. Exists here
    // only so it has a real label/slug for display and browsing.
    'general' => ['label' => 'General', 'parent' => 'General', 'keywords' => []],
];
