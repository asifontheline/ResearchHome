<?php
// Seed subjects for the harvester's API queries. This is a *starting point*,
// not the full taxonomy of possible tags — it's what drives the rotating
// keyword searches against arXiv/Crossref/PubMed (see run_api_harvest()).
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
// Add more rows any time — nothing else needs to change. Assign new entries
// to an existing 'parent' or introduce a new one; the sidebar picks it up
// automatically.

return [
    // Mathematics & Physical Sciences
    'mathematics' => ['label' => 'Mathematics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['mathematics', 'topology', 'combinatorics', 'algebra', 'number theory', 'differential equations']],
    'physics' => ['label' => 'Physics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['physics', 'quantum', 'particle physics', 'astrophysics', 'cosmology', 'relativity', 'condensed matter']],
    'chemistry' => ['label' => 'Chemistry', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['chemistry', 'catalysis', 'organic synthesis', 'chemical reaction']],
    'astronomy' => ['label' => 'Astronomy & Space', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['astronomy', 'exoplanet', 'galaxy', 'telescope', 'space mission']],
    'earth-science' => ['label' => 'Earth Science', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['geology', 'seismology', 'volcanology', 'geophysics', 'earth science']],
    'statistics' => ['label' => 'Statistics', 'parent' => 'Mathematics & Physical Sciences', 'keywords' => ['statistics', 'statistical inference', 'bayesian', 'data analysis']],

    // Computing & Engineering
    'artificial-intelligence' => ['label' => 'Artificial Intelligence', 'parent' => 'Computing & Engineering', 'keywords' => ['artificial intelligence', 'machine learning', 'neural network', 'deep learning', 'large language model', 'reinforcement learning']],
    'computer-science' => ['label' => 'Computer Science', 'parent' => 'Computing & Engineering', 'keywords' => ['algorithm', 'computer science', 'distributed systems', 'programming languages', 'cryptography', 'databases', 'software engineering']],
    'robotics' => ['label' => 'Robotics', 'parent' => 'Computing & Engineering', 'keywords' => ['robotics', 'autonomous vehicle', 'robot manipulation', 'drone']],
    'engineering' => ['label' => 'Engineering', 'parent' => 'Computing & Engineering', 'keywords' => ['mechanical engineering', 'civil engineering', 'aerospace engineering', 'structural engineering']],
    'materials-science' => ['label' => 'Materials Science', 'parent' => 'Computing & Engineering', 'keywords' => ['materials science', 'nanomaterial', 'polymer', 'semiconductor', 'alloy', 'nanotechnology']],

    // Life Sciences & Medicine
    'biology' => ['label' => 'Biology', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['biology', 'gene', 'genome', 'cell biology', 'protein', 'evolutionary biology', 'microbiology']],
    'medicine' => ['label' => 'Medicine & Health', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['medicine', 'clinical trial', 'disease', 'treatment', 'diagnosis', 'public health', 'epidemiology']],
    'neuroscience' => ['label' => 'Neuroscience', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['neuroscience', 'brain', 'cognitive', 'neuron', 'neural circuit']],
    'genetics' => ['label' => 'Genetics & Genomics', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['genetics', 'genomics', 'crispr', 'gene therapy', 'genetic variation']],
    'ecology' => ['label' => 'Ecology & Environment', 'parent' => 'Life Sciences & Medicine', 'keywords' => ['ecology', 'biodiversity', 'ecosystem', 'conservation', 'species']],

    // Environment & Energy
    'climate-science' => ['label' => 'Climate Science', 'parent' => 'Environment & Energy', 'keywords' => ['climate change', 'global warming', 'climate model', 'sea level']],
    'energy' => ['label' => 'Energy', 'parent' => 'Environment & Energy', 'keywords' => ['renewable energy', 'solar power', 'battery', 'nuclear energy', 'energy storage', 'grid']],
    'agriculture' => ['label' => 'Agriculture & Food Science', 'parent' => 'Environment & Energy', 'keywords' => ['agriculture', 'crop science', 'food science', 'farming']],

    // Social Sciences
    'economics' => ['label' => 'Economics', 'parent' => 'Social Sciences', 'keywords' => ['economics', 'econometrics', 'macroeconomic', 'labor market', 'trade policy']],
    'finance' => ['label' => 'Finance', 'parent' => 'Social Sciences', 'keywords' => ['finance', 'asset pricing', 'financial market', 'investment', 'risk management']],
    'psychology' => ['label' => 'Psychology', 'parent' => 'Social Sciences', 'keywords' => ['psychology', 'behavioral science', 'mental health', 'cognitive behavior']],
    'sociology' => ['label' => 'Sociology', 'parent' => 'Social Sciences', 'keywords' => ['sociology', 'social inequality', 'social network', 'demography']],
    'political-science' => ['label' => 'Political Science', 'parent' => 'Social Sciences', 'keywords' => ['political science', 'governance', 'public policy', 'international relations']],
    'anthropology' => ['label' => 'Anthropology', 'parent' => 'Social Sciences', 'keywords' => ['anthropology', 'ethnography', 'cultural studies']],
    'urban-planning' => ['label' => 'Urban Planning', 'parent' => 'Social Sciences', 'keywords' => ['urban planning', 'city planning', 'transportation planning', 'housing policy']],

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

    // Business
    'business' => ['label' => 'Business & Management', 'parent' => 'Business', 'keywords' => ['business management', 'organizational behavior', 'supply chain', 'marketing']],

    // Fallback only -- deliberately empty keywords, so classify_subjects()
    // never matches it via the normal keyword loop. insert_item_if_new()
    // (functions.php) applies it directly whenever every other source
    // (source-provided category, seed subject, keyword match) came back
    // with nothing, so no item is ever left with zero tags. Exists here
    // only so it has a real label/slug for display and browsing.
    'general' => ['label' => 'General', 'parent' => 'General', 'keywords' => []],
];
