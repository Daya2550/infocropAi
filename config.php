<?php
// Farm Planner Configuration

// Database and Settings handled via db.php
if (!isset($pdo)) {
    require_once 'db.php';
}

// Fetch settings from DB
function get_setting($key, $default = '') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $res = $stmt->fetch();
    return $res ? $res['setting_value'] : $default;
}

function get_db() {
    global $pdo;
    return $pdo;
}

define('GOOGLE_API_KEY', get_setting('gemini_api_key', 'AIzaSyBsx-utqdSvwExJK0zFiXQccXu-xrxAsow'));
define('GEMINI_MODEL', get_setting('gemini_model', 'gemini-1.5-flash'));

// FPDF Font Path
define('FPDF_FONTPATH', __DIR__ . '/lib/font/');

$stages = [
    [
        "num"   => 1,
        "emoji" => "🌱",
        "title" => "Crop Selection",
        "subtitle" => "Tell us about your land and goals",
        "color" => "#2e7d32",
        "fields" => [
            ["name" => "farmer_name", "label" => "Your Name", "type" => "text", "required" => true, "panel" => "new", "placeholder" => "e.g. Ramesh Kumar", "hint" => "Enter the full name of the land owner or primary farmer."],
            ["name" => "city", "label" => "City / Taluka", "type" => "text", "required" => true, "panel" => "new", "placeholder" => "e.g. Nashik", "hint" => "Enter your nearest town or Taluka name for local market relevance."],
            ["name" => "state", "label" => "State", "type" => "select", "required" => true, "panel" => "new",
             "options" => ["Andhra Pradesh","Assam","Bihar","Chhattisgarh","Gujarat","Haryana",
                           "Himachal Pradesh","Jharkhand","Karnataka","Kerala","Madhya Pradesh",
                           "Maharashtra","Manipur","Odisha","Punjab","Rajasthan","Tamil Nadu",
                           "Telangana","Uttar Pradesh","Uttarakhand","West Bengal","Other"],
             "hint" => "Selecting your state helps us check specific Mandi prices and Government MSP."],
            ["name" => "land_area", "label" => "Land Area (Acres)", "type" => "number", "required" => true, "panel" => "new", "placeholder" => "e.g. 5", "min" => 0.1, "step" => 0.1, "hint" => "Total area you plan to sow this crop in (1 Acre ≈ 0.4 Hectares)."],
            ["name" => "soil_type", "label" => "Soil Type", "type" => "select", "required" => true, "panel" => "new",
             "options" => ["Select", "Black (Regur)", "Red & Laterite", "Alluvial", "Sandy / Desert", "Loamy", "Clay", "I don't know"],
             "hint" => "Black soil is great for cotton/soybean; Red for pulses; Alluvial for wheat/rice."],
            ["name" => "season", "label" => "Current Season", "type" => "select", "required" => true, "panel" => "new",
             "options" => ["Select", "Kharif (Jun–Oct)", "Rabi (Nov–Mar)", "Zaid (Mar–Jun)"],
             "hint" => "Kharif is monsoon season; Rabi is winter; Zaid is summer."],
            ["name" => "budget", "label" => "Total Budget (₹)", "type" => "number", "required" => true, "panel" => "new", "placeholder" => "e.g. 50000", "min" => 1000, "hint" => "Include costs for seeds, fertilizer, labor, and transport."],
            ["name" => "water_source", "label" => "Water Source", "type" => "select", "required" => true, "panel" => "new",
             "options" => ["Select", "Canal (nehr)", "Borewell", "Rain-fed only", "River / Pond", "Mixed"],
             "hint" => "Borewell/Canal allow for multi-seasonal farming; Rain-fed depends on monsoon."],
            ["name" => "crop_goal", "label" => "Main Goal", "type" => "select", "required" => false, "panel" => "new",
             "options" => ["Maximum profit", "Family food security", "Both profit + food", "Export quality crop"],
             "hint" => "Do you want to sell everything in the market or keep some for home use?"],
            ["name" => "labor_type", "label" => "Labor Available", "type" => "select", "required" => false, "panel" => "new",
             "options" => ["Family only", "1–2 hired workers", "5+ hired workers", "Mechanized (tractor etc.)", "Mixed"],
             "hint" => "Family-only labor reduces visible costs but limits scale of operations."],
            // ── OLD PLANT SPECIFIC FIELDS (shown only in Old Plant tab via JavaScript) ──
            ["name" => "crop_name_old",      "label" => "Crop Name",                           "type" => "text",   "required" => false, "panel" => "old", "placeholder" => "e.g. Pomegranate, Mango, Grapes",          "hint" => "Enter the name of your existing crop or orchard."],
            ["name" => "crop_variety_old",   "label" => "Variety / Cultivar",                  "type" => "text",   "required" => false, "panel" => "old", "placeholder" => "e.g. Bhagwa, Alphonso, Thompson Seedless",  "hint" => "Specific variety helps get accurate stage-based advice."],
            ["name" => "planting_date_old",  "label" => "Original Planting / Grafting Date",   "type" => "date",   "required" => false, "panel" => "old",                                                               "hint" => "When were the plants first planted or grafted? Estimate if unsure."],
            ["name" => "plant_age_value",    "label" => "Plant / Tree Age (Number)",            "type" => "number", "required" => false, "panel" => "old", "placeholder" => "e.g. 13",  "min" => 1,                     "hint" => "Enter the age as a number. E.g. 13 (months) or 2 (years)."],
            ["name" => "plant_age_unit",     "label" => "Age Unit",                             "type" => "select", "required" => false, "panel" => "old",
             "options" => ["Months", "Years"],
             "hint" => "Select whether the age is in Months or Years."],
            ["name" => "plant_spacing",      "label" => "Plant Spacing",                        "type" => "text",   "required" => false, "panel" => "old", "placeholder" => "e.g. 5m × 5m or 18ft × 18ft",              "hint" => "Row-to-row and plant-to-plant spacing in the orchard."],
            ["name" => "plants_per_acre",    "label" => "Plants Per Acre",                      "type" => "number", "required" => false, "panel" => "old", "placeholder" => "e.g. 400", "min" => 1,                      "hint" => "Total number of plants/trees per acre in your orchard."],
            ["name" => "avg_tree_height",    "label" => "Average Plant / Tree Height",          "type" => "text",   "required" => false, "panel" => "old", "placeholder" => "e.g. 4 feet, 1.5 meters, knee height",       "hint" => "Average height of most plants. Helps determine growth stage."],
            ["name" => "plant_health_status","label" => "Overall Plant Health",                 "type" => "select", "required" => false, "panel" => "old",
             "options" => ["Good (Healthy, growing well)", "Medium (Some stress or slow growth)", "Weak (Yellowing, disease, poor growth)"],
             "hint" => "Your honest assessment of most plants in the field right now."],
        ],
        "gemini_key" => "crop_recommendation",
        "prompt" => "You are an expert Indian agricultural advisor. A farmer has shared the following details:\n\n👤 Farmer: {farmer_name}\n📍 Location: {city}, {state}\n🌾 Land: {land_area} acres\n🌍 Soil Type: {soil_type}\n📅 Season: {season}\n💰 Budget: ₹{budget}\n💧 Water: {water_source}\n🎯 Goal: {crop_goal}\n👷 Labor: {labor_type}\n📆 Current Date: {date}\n\nProvide a comprehensive structured farm advisory in this EXACT order:\n\n## 🔍 FARM FEASIBILITY ASSESSMENT\n\nCreate a table with these 5 rows:\n\n| Factor | Current Status | Recommended Decision |\n|--------|---------------|----------------------|\n| Water availability | [assess based on {water_source}] | [recommendation] |\n| Budget | ₹{budget} | [Basic/Standard/Premium input model] |\n| Soil fertility | [assess based on {soil_type}] | [recommendation] |\
| Heat/season risk | [assess based on {season} in {state}] | [recommendation] |\
| Labor availability | {labor_type} | [recommendation] |\n\nAfter the table, add a short 2-line feasibility verdict: Is this plan VIABLE / NEEDS ADJUSTMENT / HIGH RISK?\n\n## 🌾 CROP RECOMMENDATION\n\n**TOP_CROP:** [Best single crop with 1-line reason specific to {state} + {season}]\n\n**ALTERNATIVES:** [Provide EXACTLY 5 alternative crops with brief reasons emphasizing market value and climate fit]\n1. [Alternative 1] - [Reason]\n2. [Alternative 2] - [Reason]\n3. [Alternative 3] - [Reason]\n4. [Alternative 4] - [Reason]\n5. [Alternative 5] - [Reason]\n\n**WHY BEST:** [3 specific reasons why the TOP_CROP suits {city}, {state} in {season} with this budget]\n\n## 📈 MARKET OUTLOOK\n\nCurrent market trend for recommended crop in {state} — include current MSP 2024-25 if applicable.\n\n## 📅 SEASONAL TIMING ADVICE\n\nKey sowing/harvest timing advice for {state} right now ({date}).\n\n## 💰 BUDGET FIT\n\nHow this crop fits ₹{budget} budget with rough cost breakdown table:\n\n| Cost Item | ₹ per Acre | Total for {land_area} Acres |\n|-----------|-----------|-----------------------------|\n| Seeds | | |\n| Fertilizer/Spraying | | |\n| Labor | | |\n| Irrigation | | |\n| Pesticides | | |\
| **Total** | | |\n\n## 📊 YIELD SCENARIOS\n\n| Scenario | Yield (Qtl/{land_area} acres) | Gross Revenue | Key Factor |\n|----------|-------------------------------|---------------|------------|\n| Poor season | | | |\n| Normal season | | | |\
| Good management | | | |\n\nBe specific to {state} conditions. Use real 2024-25 MSP/market prices.",
        "prompt_old" => "You are an expert Indian agricultural advisor specializing in perennial crops and orchards. A farmer has shared the following details about their existing crop:\n\n👤 Farmer: {farmer_name}\n📍 Location: {city}, {state}\n🌳 Crop: {crop_name_old} ({crop_variety_old})\n🌱 Planted: {planting_date_old} ({plant_age_value} {plant_age_unit} old)\n📏 Spacing: {plant_spacing} ({plants_per_acre} plants/acre)\n🌿 Avg Height: {avg_tree_height}\n💪 Health: {plant_health_status}\n🌾 Land: {land_area} acres\n🌍 Soil Type: {soil_type}\n📅 Current Season: {season}\n💰 Budget: ₹{budget}\n💧 Water: {water_source}\n🎯 Goal: {crop_goal}\n👷 Labor: {labor_type}\n📆 Current Date: {date}\n\nProvide a comprehensive structured farm advisory for this existing crop in this EXACT order:\n\n## 🔍 ORCHARD HEALTH ASSESSMENT\n\nCreate a table with these 5 rows:\n\n| Factor | Current Status | Recommended Decision |\n|--------|---------------|----------------------|\n| Water availability | [assess based on {water_source}] | [recommendation] |\n| Budget | ₹{budget} | [Basic/Standard/Premium input model] |\
| Soil fertility | [assess based on {soil_type}] | [recommendation] |\
| Heat/season risk | [assess based on {season} in {state}] | [recommendation] |\
| Plant health | {plant_health_status} | [recommendation] |\n\nAfter the table, add a short 2-line feasibility verdict: Is this orchard plan VIABLE / NEEDS ADJUSTMENT / HIGH RISK?\n\n## 🌳 CROP MANAGEMENT ADVICE\n\n**CURRENT STAGE:** [Identify the current growth stage of {crop_name_old} based on {plant_age_value} {plant_age_unit} and {season} in {state}]\n\n**KEY ACTIVITIES:** [3 most critical activities for {crop_name_old} at this stage in {state} + {season}]\n1. [Activity 1] - [Reason]\n2. [Activity 2] - [Reason]\n3. [Activity 3] - [Reason]\n\n**WHY THIS ADVICE:** [3 specific reasons why this advice suits {city}, {state} for {crop_name_old} with this budget and health status]\n\n## 📈 MARKET OUTLOOK\n\nCurrent market trend for {crop_name_old} in {state} — include current MSP 2024-25 if applicable (if {crop_name_old} is covered).\n\n## 📅 SEASONAL TIMING ADVICE\n\nKey pruning/flowering/harvest timing advice for {crop_name_old} in {state} right now ({date}).\n\n## 💰 BUDGET FIT\n\nHow current management fits ₹{budget} budget with rough cost breakdown table:\n\n| Cost Item | ₹ per Acre | Total for {land_area} Acres |\n|-----------|-----------|-----------------------------|\
| Fertilization | | |\
| Pest/Disease Control | | |\
| Irrigation | | |\
| Pruning/Training | | |\
| Labor | | |\
| **Total** | | |\n\n## 📊 YIELD SCENARIOS\n\n| Scenario | Yield (Qtl/{land_area} acres) | Gross Revenue | Key Factor |\n|----------|-------------------------------|---------------|------------|\
| Poor season | | | |\
| Normal season | | | |\
| Good management | | | |\n\nBe specific to {state} conditions. Use real 2024-25 market prices for {crop_name_old}."
    ],
    [
        "num"   => 2,
        "emoji" => "🌾",
        "title" => "Seed Selection",
        "subtitle" => "Choose the right seed variety for your crop",
        "color" => "#558b2f",
        "fields" => [
            ["name" => "confirmed_crop", "label" => "Confirmed Crop", "type" => "text", "required" => true, "placeholder" => "From AI recommendation or your choice", "hint" => "Enter the specific crop you have decided to grow (e.g. Wheat, Cotton, Black Gram)."],
            ["name" => "seed_preference", "label" => "Seed Preference", "type" => "select", "required" => false,
             "options" => ["No preference", "Hybrid (high yield)", "Traditional/Desi variety", "Organic certified", "Government supplied"],
             "hint" => "Hybrid seeds yield more but need more water/fertilizer; Desi are more resilient."],
            ["name" => "irrigation_avail","label" => "Is irrigation available?","type"=>"select", "required" => true,
             "options" => ["Yes – full irrigation", "Partial / limited water", "Rain-fed only"],
             "hint" => "Full irrigation is needed for water-intensive crops like Sugarcane/Paddy."],
            ["name" => "prev_crop", "label" => "Last crop grown here", "type" => "text", "required" => false, "placeholder" => "e.g. Wheat, or 'None'", "hint" => "Knowing the previous crop helps in avoiding soil-borne diseases."],
            ["name" => "disease_history", "label" => "Any known disease problems in this field?","type"=>"text","required"=>false,"placeholder"=>"e.g. Root rot, leaf blight, or 'None'", "hint" => "Mention past issues like Wilt, Blast, or Rust to get preventive AI advice."],
        ],
        "gemini_key" => "seed_advice",
        "prompt" => "You are an expert Indian seed scientist and agronomist.\n\nFARMER CONTEXT:\n- Location: {city}, {state}\n- Crop chosen: {confirmed_crop}\n- Land: {land_area} acres, Soil: {soil_type}\n- Season: {season}\n- Irrigation: {irrigation_avail}\n- Previous crop: {prev_crop}\n- Known diseases: {disease_history}\n- Budget: ₹{budget}\n- Date: {date}\n\nProvide detailed structured seed advice:\n\n## 🌱 VARIETY RECOMMENDATION\n\n**BEST_VARIETY:** [Top variety name + certifying body]\n**Note:** Clarify clearly whether this is a HYBRID, IMPROVED VARIETY, or TRADITIONAL DESI variety — most pulse varieties are improved varieties, not true hybrids.\n\n**WHY THIS VARIETY:** [3 specific reasons for {state} + {season}]\n\n## 📦 SEED QUANTITY & COST\n\n| Item | Details |\n|------|--------|\n| Seed rate | X kg/acre |\n| Total for {land_area} acres | X kg |\n| Approx cost | ₹X |\n| Source | Government/ICAR/Private brand in {state} |\n\n## 🛒 WHERE TO BUY\n\nBest sources specific to {state}: government seed stores, ICAR stations, private certified brands.\n\n## 🌿 GERMINATION TIPS\n\n3 specific tips to maximize germination rate in {state} climate for {season}:\n1. [Tip]\n2. [Tip]\n3. [Tip]\n\n## 🛡️ DISEASE RESISTANCE\n\nIs this variety resistant to: {disease_history}?\n- YMV resistance: [Yes/No/Partial]\n- Other key resistances: [list]\n\n**Important:** Preventive chemical seed treatment is recommended **only if** you have a confirmed history of {disease_history}. If field is clean, seed priming with water or bio-agents is sufficient.\n\n## 🔄 CROP ROTATION BENEFIT\n\nBenefit of growing {confirmed_crop} after {prev_crop}: [Specific benefit for soil health]\n\n## ⚠️ CONSISTENCY CHECK\n\nIf crop selection differs from Stage 1 advice, explain any yield/input differences here.\n\nBe specific to India and the current {season}."
    ],
    [
        "num"   => 3,
        "emoji" => "🌍",
        "title" => "Soil Health",
        "subtitle" => "Understand your soil and get a fertilizer plan",
        "color" => "#6d4c41",
        "fields" => [
            ["name" => "ph_level", "label" => "Soil pH (if known)", "type" => "number","required" => false, "placeholder" => "e.g. 6.5", "min" => 0, "max" => 14, "step" => 0.1, "hint" => "Acidic (< 6.5), Neutral (6.5–7.5), Alkaline (> 7.5). Neutral is ideal for most crops."],
            ["name" => "nitrogen", "label" => "Nitrogen level (N)", "type" => "select","required" => false,
             "options" => ["Unknown", "Very Low", "Low (< 280 kg/ha)", "Medium (280–560 kg/ha)", "High (> 560 kg/ha)"],
             "hint" => "Nitrogen is key for leaf growth. Levels vary based on your soil test report."],
            ["name" => "phosphorus", "label" => "Phosphorus level (P)", "type" => "select","required" => false,
             "options" => ["Unknown", "Very Low", "Low (< 10 kg/ha)", "Medium (10–25 kg/ha)", "High (> 25 kg/ha)"],
             "hint" => "Phosphorus is essential for root and fruit development."],
            ["name" => "potassium", "label" => "Potassium level (K)", "type" => "select","required" => false,
             "options" => ["Unknown", "Very Low", "Low (< 108 kg/ha)", "Medium (108–280 kg/ha)", "High (> 280 kg/ha)"],
             "hint" => "Potassium builds immunity and improves crop quality."],
            ["name" => "organic_matter","label" => "Organic matter / compost used?","type"=>"select","required"=>false,
             "options" => ["No", "Yes – FYM / cow dung", "Yes – vermicompost", "Yes – green manure"],
             "hint" => "Using cow dung (Gobar) or vermicompost improves soil structure long-term."],
            ["name" => "last_fertilizer","label" => "Last fertilizer used", "type" => "text", "required" => false, "placeholder" => "e.g. DAP + Urea, or None", "hint" => "Helps to avoid over-fertilization of certain nutrients."],
        ],
        "gemini_key" => "soil_plan",
        "prompt" => "You are a certified soil scientist advising Indian farmers.\n\nFARM DETAILS:\n- Location: {city}, {state}\n- Crop: {confirmed_crop}\n- Land: {land_area} acres\n- Soil type: {soil_type}\n- Soil pH: {ph_level}\n- Nitrogen: {nitrogen} | Phosphorus: {phosphorus} | Potassium: {potassium}\n- Organic matter: {organic_matter}\n- Last fertilizer: {last_fertilizer}\n- Budget: ₹{budget}\n\nProvide a complete soil and fertilizer management plan:\n\n## 🌍 SOIL ASSESSMENT\n\nCurrent soil health summary for {soil_type} in {state}. Mention key concerns.\n\n**pH Status:** Is {ph_level} suitable for {confirmed_crop}? Target pH range?\n\n## 🧪 FERTILIZER & SPRAYING SCHEDULE\n\nDetailed stage-wise fertilizer and foliar spraying plan:\n\n| Stage | Timing | Method | Fertilizer / Spray Name | Quantity/Acre | Cost/Acre |\n|-------|--------|--------|-------------------------|---------------|-----------|\n| Default Soil Prep | Pre-sowing | Broadcast | [Recommend additions] | | |\n| Basal Dose | At sowing | Band/Drill | | | |\n| Vegetative Growth| [Days] | Top-dress | | | |\n| Foliar Spray 1 | [Days] | Spraying | [Recommend micronutrient/growth spray] | | |\n| Pre-Flowering | [Days] | Top-dress / Drip | | | |\n| Grain/Fruit Fill | [Days] | Spraying | [Recommend spray if needed] | | |\n| **Total cost** | | | | | ₹X/acre |\n\nUse specific Indian brand names (IFFCO, Coromandel, etc.) and current 2024-25 prices. Ensure you provide clear instructions on additional spraying where required.\n\n## 🌿 ORGANIC ADDITIONS\n\nRecommended organic inputs and their benefit for {confirmed_crop}:\n- FYM: X tonnes/acre\n- Vermicompost: X kg/acre\n- Provide advice on incorporating these to reduce chemical costs.\n\n## ⚗️ MICRONUTRIENTS TO WATCH\n\n**Zinc Deficiency:**\n- Common in {soil_type} soils of {state}\n- Symptoms to watch: [describe early signs]\n- ⚠️ CAUTION: Apply zinc only after confirmed soil test deficiency. Unconfirmed application may harm crop.\n- If confirmed deficient: Zinc Sulphate @ X kg/acre as basal dose\n\nOther micronutrients: [list any others relevant to {state} + {confirmed_crop} and specific foliar spray corrections if needed]\n\n## 🏛️ SOIL TEST CENTERS IN {state}\n\nWhere to get soil tested under Government Soil Health Card (SHC) scheme — free at government labs.\n\n## 💰 TOTAL FERTILIZER & SPRAY COST\n\nEstimated total fertilizer cost for {land_area} acres: ₹X – ₹Y\n\n**Note:** If budget is tight (₹{budget}), prioritize basal application and critical vegetative top-dressing. Skip secondary sprays only if crop looks perfectly healthy."
    ],
    [
        "num"   => 4,
        "emoji" => "💧",
        "title" => "Water Management",
        "subtitle" => "Irrigation schedule and water requirements",
        "color" => "#0277bd",
        "fields" => [
            ["name" => "irrigation_method", "label" => "Irrigation Method", "type" => "select","required" => true,
             "options" => ["Select", "Flood/Furrow", "Sprinkler", "Drip", "Rain-fed only"],
             "hint" => "Drip irrigation saves up to 50% water and is best for row crops like fruit/cotton."],
            ["name" => "water_availability","label" => "Water availability", "type" => "select","required" => true,
             "options" => ["Abundant (canal/river nearby)", "Moderate (borewell)", "Scarce (rain-fed/shared)"],
             "hint" => "Determines how often you can irrigate during dry spells."],
            ["name" => "pump_hp", "label" => "Pump HP (if borewell/motor)", "type" => "number","required" => false, "placeholder" => "e.g. 3", "min" => 0.5, "step" => 0.5, "hint" => "Enter your pump motor power in HP. Used to calculate how many hours you need to irrigate."],
            ["name" => "borewell_output", "label" => "Borewell output (L/hour, if known)", "type" => "number","required" => false, "placeholder" => "e.g. 10000", "hint" => "Leave blank if unknown — AI will estimate based on typical {state} borewell output."],
            ["name" => "avg_rainfall", "label" => "Avg annual rainfall (mm)","type"=>"number","required"=>false,"placeholder"=>"e.g. 800", "hint" => "If unknown, leave blank. AI will use regional averages for {state}."],
            ["name" => "sowing_date", "label" => "Planned Sowing Date", "type" => "date", "required" => true, "hint" => "Sowing at the right time is critical for maximum yield."],
            ["name" => "land_slope", "label" => "Land Terrain", "type" => "select","required" => false,
             "options" => ["Flat", "Slight slope", "Hilly / terrace farming"],
             "hint" => "Slope affects water runoff and soil erosion management."],
        ],
        "gemini_key" => "irrigation_plan",
        "prompt" => "You are an irrigation engineer and water management expert for Indian agriculture.\n\nFARM PROFILE:\n- Location: {city}, {state}\n- Crop: {confirmed_crop}\n- Land: {land_area} acres, Terrain: {land_slope}\n- Sowing planned: {sowing_date} ({season})\n- Irrigation method: {irrigation_method}\n- Water availability: {water_availability}\n- Pump HP: {pump_hp} HP | Borewell output: {borewell_output} L/hr\n- Annual rainfall: {avg_rainfall} mm\n- Water source: {water_source}\n\nProvide a complete irrigation plan:\n\n## 💧 WATER REQUIREMENT\n\nTotal water needed for {confirmed_crop} on {land_area} acres:\n- Total seasonal requirement: X–Y mm (X–Y lakh litres)\n- Note: Zaid crops in semi-arid {state} cannot be rain-fed and need supplemental irrigation.\n\n## 🕐 PUMP-HOURS CALCULATOR\n\n*(Farmers think in pump-hours, not litres)*\n\n| Parameter | Value |\n|-----------|-------|\n| Pump HP | {pump_hp} HP |\n| Estimated output | {borewell_output} L/hr (or estimated X L/hr) |\n| Irrigation frequency | Every X days |\n| Hours needed per irrigation | X hours |\n| Total pump hours per season | X hours |\n| Electricity cost estimate | ₹X/season |\n\nIf pump HP or output is unknown, use typical values for {state} borewells.\n\n## 📅 IRRIGATION SCHEDULE\n\nWeek-by-week irrigation schedule from sowing to harvest:\n\n| Week | Crop Stage | Irrigation Needed | Frequency | Duration |\n|------|-----------|-------------------|-----------|----------|\n| Week 1–2 | Germination | | | |\n| Week 3–4 | Seedling | | | |\n| Week 5–6 | Vegetative | | | |\n| Week 7–8 | Flowering | | | |\n| Week 9–10 | Pod/Grain filling | | | |\n\n## 💡 IRRIGATION METHOD ADVICE\n\nIs {irrigation_method} optimal for {confirmed_crop} + {land_area} acres? If not, what to switch to?\n\nDrip irrigation recommendation for Zaid/summer crops in {state}: saves 40–50% water.\n\n## 🏛️ SUBSIDIES\n\nGovernment water/drip irrigation subsidies available in {state} under PM Krishi Sinchayee Yojana (PMKSY). How to apply?\n\n## ⚠️ WARNING SIGNS\n\n| Condition | Signs on Crop | Immediate Action |\n|-----------|-------------|------------------|\n| Over-watering | | |\n| Under-watering | | |\n| Soil cracking | | Skip non-critical irrigation |"
    ],
    [
        "num"   => 5,
        "emoji" => "🌦️",
        "title" => "Weather Planning",
        "subtitle" => "Seasonal weather forecast and risk planning",
        "color" => "#00695c",
        "fields" => [
            ["name" => "harvest_month", "label" => "Expected Harvest Month", "type" => "select","required" => true,
             "options" => ["January","February","March","April","May","June","July","August","September","October","November","December"],
             "hint" => "Helps predictable harvest timing to avoid late-season rains."],
            ["name" => "climate_concern", "label" => "Main weather concern", "type" => "select","required" => false,
             "options" => ["No concern", "Drought/less rain", "Flood/heavy rain", "Frost/cold wave", "Extreme heat", "Cyclone risk"],
             "hint" => "Mention your top fear to get a specific risk-mitigation plan."],
            ["name" => "nearest_town", "label" => "Nearest major town/city", "type" => "text", "required" => false, "placeholder" => "For weather reference", "hint" => "Used to fetch precise regional weather forecasts."],
        ],
        "gemini_key" => "weather_plan",
        "prompt" => "You are a meteorologist and agricultural climate expert for India.\n\nFARM DATA:\n- Location: {city}, {state} (near {nearest_town})\n- Crop: {confirmed_crop}\n- Sowing: {sowing_date} | Expected harvest: {harvest_month}\n- Season: {season}\n- Main weather concern: {climate_concern}\n- Today: {date}\n\nProvide real-time seasonal weather planning advice:\n\n## 🌤️ SEASONAL WEATHER OUTLOOK\n\nWeather pattern expected for {state} during {season} based on IMD historical data.\n\n⚠️ **Important Accuracy Note:** Weather forecasts beyond 2–3 weeks are PROBABILISTIC, not guaranteed. All predictions below are based on historical IMD patterns and should be treated as estimates, not certainties.\n\n## 📅 CRITICAL WEATHER WINDOWS\n\n4 specific weather windows critical for {confirmed_crop} from sowing to harvest:\n\n| Period | Expected Weather | Risk to Crop | Action |\n|--------|-----------------|-------------|--------|\n| Sowing time | | | |\n| Vegetative stage | | | |\n| Flowering stage | | | |\n| Harvest time | | | |\n\n## ⚡ MAJOR RISKS & ACTION PLAN\n\n| Risk | Early Warning Sign | Immediate Action |\n|------|-------------------|------------------|\n| Heat stress | Flower/pod dropping | Light irrigation + foliar spray |\n| Water shortage | Soil cracking | Skip non-critical irrigation |\n| Pest surge (post-rain) | Leaf curling/discolouration | Install traps + targeted spray |\n| Heavy rain | Waterlogging signs | Open drainage channels |\n| Market price fall | Low mandi rate | Delay sale 7–10 days if storage available |\n\n## 🌡️ TEMPERATURE ALERTS\n\nTemperature extremes to watch for {state} during {season}:\n- Dangerous high: above X°C → effect on {confirmed_crop}\n- Optimal range: X–Y°C\n\n## 📱 WEATHER APPS FOR FARMERS\n\n3 best weather apps for Indian farmers (with Hindi/regional language options):\n1. Meghdoot App (IMD) — Free, Hindi/regional language\n2. Kisan Suvidha App — Weather + market prices\n3. Damini (lightning alert) — Safety during monsoon\n\n## 🛡️ CROP INSURANCE\n\nPradhan Mantri Fasal Bima Yojana (PMFBY) for {state}:\n- How to apply\n- Premium % for {confirmed_crop}\n- Claim process\n- Deadline for {season} enrollment"
    ],
    [
        "num"   => 6,
        "emoji" => "🐛",
        "title" => "Pest & Disease Control",
        "subtitle" => "Early prevention and treatment strategy",
        "color" => "#e65100",
        "fields" => [
            ["name" => "pest_history", "label" => "Past pest/disease problems", "type" => "text", "required" => false, "placeholder" => "e.g. Aphids, powdery mildew, or None", "hint" => "AI will suggest specific resistant varieties or preventive sprays."],
            ["name" => "neighbors_crop","label" => "Neighboring field crops", "type" => "text", "required" => false, "placeholder" => "e.g. Cotton, Wheat", "hint" => "Pests often migrate from neighboring fields (e.g. Whitefly from cotton)."],
            ["name" => "pesticide_pref","label" => "Pesticide preference", "type" => "select","required" => false,
             "options" => ["No preference", "Chemical (fast acting)", "Organic / bio-pesticide", "Integrated Pest Management (IPM)", "Minimum chemicals"],
             "hint" => "IPM is recommended as it combines organic and chemical for best results."],
            ["name" => "spray_equipment","label" => "Spray equipment available", "type" => "select","required" => false,
             "options" => ["Manual knapsack sprayer", "Electric sprayer", "Tractor-mounted sprayer", "Drone spraying (hired)", "None – need advice"],
             "hint" => "Drones are increasingly used for uniform spraying in large areas."],
        ],
        "gemini_key" => "pest_plan",
        "prompt" => "You are an expert entomologist and plant pathologist for Indian agriculture.\n\nFARM PROFILE:\n- Location: {city}, {state}\n- Crop: {confirmed_crop}\n- Season: {season}, Sowing: {sowing_date}\n- Past pest/disease: {pest_history}\n- Neighboring crops: {neighbors_crop}\n- Pesticide preference: {pesticide_pref}\n- Spray equipment: {spray_equipment}\n- Current date: {date}\n\nProvide a comprehensive Integrated Pest Management (IPM) plan:\n\n## 🐛 TOP THREATS\n\n4 major pests/diseases threatening {confirmed_crop} in {state} during {season}:\n\n| Pest/Disease | Early Symptoms | Peak Risk Period | Impact |\n|-------------|---------------|-----------------|--------|\n| | | | |\n| | | | |\n\n## 📅 PREVENTION CALENDAR\n\nMonth-by-month preventive schedule from sowing to harvest:\n\n| Month/Week | Action | Product | Dose |\n|-----------|--------|---------|------|\n| At sowing | Seed treatment (only if {pest_history} confirmed) | | |\n| Week 2–3 | Scout for early pests | — | — |\n| Week 4–5 | | | |\n\n⚠️ **IPM Principle:** Use chemicals ONLY when pest population crosses economic threshold. Monitor first, spray only if necessary.\n\n**Seed Treatment Note:** Preventive chemical seed treatment is recommended ONLY if you have confirmed {pest_history}. New or clean fields: Use bio-seed treatment agents (Trichoderma, Rhizobium) only.\n\n## 🌿 ORGANIC / BIO-PESTICIDE OPTIONS\n\n| Product | Target | How to Use | Availability in {state} |\n|---------|--------|-----------|------------------------|\n| Neem oil (3000 ppm) | | | |\n| Beauveria bassiana | | | |\n| Trichoderma | | | |\n\n## 💊 CHEMICAL OPTIONS (IF NEEDED)\n\n| Chemical | Target Pest | Dose/Acre | Safety Interval (days) | Available Brand |\n|---------|------------|-----------|----------------------|----------------|\n| | | | | |\n\n## 🌾 NEIGHBOR RISK\n\nRisk from neighboring {neighbors_crop}: [specific pest/disease migration risk and buffer strategy]\n\n## 🚨 EARLY WARNING SIGNS — ACT IMMEDIATELY\n\n| Sign | Likely Problem | Immediate Action |\n|------|--------------|------------------|\n| Leaf curling | | |\n| Yellow patches | | |\n| Holes in leaves | | |\n| Sticky residue | | |\n| Flower dropping | | |\n\n## 💰 PEST MANAGEMENT COST\n\nEstimated full-season pest management cost for {land_area} acres: ₹X – ₹Y\nIPM approach saves approx. X% vs. full-chemical approach."
    ],
    [
        "num"   => 7,
        "emoji" => "💰",
        "title" => "Market Awareness",
        "subtitle" => "Best prices, markets and selling strategy",
        "color" => "#1565c0",
        "fields" => [
            ["name" => "nearest_mandi", "label" => "Nearest APMC Mandi", "type" => "text", "required" => false, "placeholder" => "e.g. Nashik, Pune", "hint" => "Check 'Agmarknet' portal for current prices in these Mandis."],
            ["name" => "sell_preference","label" => "How do you prefer to sell?", "type" => "select","required" => false,
             "options" => ["At local mandi", "Direct to trader/broker", "FPO / cooperative", "Online (eNAM)", "Government procurement (MSP)"],
             "hint" => "FPOs often give better prices by removing middlemen."],
            ["name" => "expected_harvest_qty","label" => "Expected yield (quintals)","type"=>"number","required"=>false,"placeholder"=>"e.g. 40", "min" => 1, "hint" => "1 Quintal = 100 kg. Used to calculate your total profit."],
            ["name" => "storage_facility", "label" => "Do you have storage?", "type" => "select","required" => false,
             "options" => ["No storage", "Home storage (kutcha)", "Pucca warehouse", "Cold storage access", "FPO/cooperative storage"],
             "hint" => "Good storage allows you to wait for higher market prices later in the year."],
        ],
        "gemini_key" => "market_plan",
        "prompt" => "You are an expert Indian agricultural market analyst.\n\nFARM PROFILE:\n- Crop: {confirmed_crop}\n- Location: {city}, {state}\n- Expected harvest: {harvest_month} | Quantity: {expected_harvest_qty} quintals\n- Nearest mandi: {nearest_mandi}\n- Selling preference: {sell_preference}\n- Storage: {storage_facility}\n- Today: {date}\n\nProvide real-time market intelligence:\n\n## 💹 CURRENT PRICE OVERVIEW\n\n**Government MSP 2024-25 for {confirmed_crop}:** ₹X/quintal (official)\n\n**Estimated current market price in {state}:** ₹X–Y/quintal\n\n⚠️ **Market Forecast Disclaimer:** Price predictions beyond the current month are uncertain. They are influenced by monsoon rainfall, import/export policy, government procurement, and global commodity rates. The forecasts below are ESTIMATES only — verify with live Agmarknet or eNAM data before selling.\n\n## 🕰️ HISTORICAL 2-YEAR MARKET RATES (MONTH-WISE)\n\nProvide an estimated month-wise breakdown comparing the average mandi rates for {confirmed_crop} in {state} over the last 2 years (2022-2023 vs 2023-2024). \n\n| Month | 2 Years Ago Avg (₹/qtl) | 1 Year Ago Avg (₹/qtl) | Trend Insight |\n|-------|-------------------------|------------------------|---------------|\n| Jan | | | |\n| Feb | | | |\n| Mar | | | |\n| Apr | | | |\n| May | | | |\n| Jun | | | |\n| Jul | | | |\n| Aug | | | |\n| Sep | | | |\n| Oct | | | |\n| Nov | | | |\n| Dec | | | |\n\n## 📊 FUTURE PRICE FORECAST\n\nExpected price during {harvest_month}: Will it go up ↑ or down ↓?\n\n| Period | Estimated Price Range | Reason based on historical trends |\n|--------|----------------------|-----------------------------------|\n| Now | ₹X–Y/qtl | |\n| {harvest_month} | ₹X–Y/qtl | |\n| 1 month post-harvest | ₹X–Y/qtl | |\n\n## 🏪 SELLING DECISION GUIDE\n\n| Market Price You See | Recommended Action |\n|---------------------|-------------------|\n| > ₹X/qtl (above MSP+20%) | Sell immediately |\n| ₹X–Y/qtl (near MSP) | Compare nearby mandi rates first |\n| < ₹X/qtl (below MSP) | Store short-term if possible, or sell at MSP procurement center |\n\n## 🗺️ TOP MANDIS NEAR {city}\n\nTop 3 mandis near {city}, {state} with typical {confirmed_crop} prices:\n1. [Mandi 1] — ₹X/qtl\n2. [Mandi 2] — ₹X/qtl\n3. [Mandi 3] — ₹X/qtl\n\nCheck live prices: agmarknet.gov.in or eNAM app.\n\n## 💻 DIGITAL SELLING (eNAM)\n\nHow to use eNAM online mandi for {confirmed_crop} in {state}.\n\n## 📦 STORAGE STRATEGY\n\nIf storing: how long to store {confirmed_crop} and expected price appreciation.\n\n## 💵 REVENUE ESTIMATE\n\nEstimated revenue for {expected_harvest_qty} quintals:\n\n| Price Scenario | Rate | Total Revenue | Net Profit (after ₹{budget} cost) |\n|--------------|------|---------------|-----------------------------------|\n| Low price | ₹X/qtl | ₹X | ₹X |\n| Medium price | ₹X/qtl | ₹X | ₹X |\n| High price | ₹X/qtl | ₹X | ₹X |"
    ],
    [
        "num"   => 8,
        "emoji" => "🚜",
        "title" => "Farming Schedule",
        "subtitle" => "Step-by-step crop growth management",
        "color" => "#4a148c",
        "fields" => [
            ["name" => "labor_available", "label" => "Labor available", "type" => "select","required" => false,
             "options" => ["Family only", "1-2 hired workers", "5+ hired workers", "Mechanized (tractor etc.)", "Mixed"],
             "hint" => "Family labor reduces visible costs but takes significant time."],
            ["name" => "farm_machinery", "label" => "Farm equipment owned", "type" => "text", "required" => false, "placeholder" => "e.g. Tractor, pump, or None", "hint" => "AI will suggest hiring machines for specific tasks if you don't own them."],
            ["name" => "special_concerns", "label" => "Any special concerns", "type" => "text", "required" => false, "placeholder" => "e.g. Late monsoon, power cuts", "hint" => "Mention any local issues like wild animal attacks or electricity shortages."],
        ],
        "gemini_key" => "farming_schedule",
        "prompt" => "You are an expert agricultural extension officer for {state}.\n\nCOMPLETE FARM PROFILE:\n- Farmer: {farmer_name}, {city}, {state}\n- Crop: {confirmed_crop}\n- Land: {land_area} acres, Soil: {soil_type}\n- Sowing: {sowing_date}, Harvest: {harvest_month}\n- Season: {season}\n- Labor: {labor_available}\n- Equipment: {farm_machinery}\n- Concerns: {special_concerns}\n\nCreate a complete farm management calendar:\n\n## 📅 WEEK-BY-WEEK ACTIVITY CALENDAR\n\n| Week | Crop Stage | Key Activity | Who/Equipment | Cost (₹) |\n|------|-----------|-------------|--------------|----------|\n| Week 1 | Sowing | Land prep + seed treatment + sowing | | |\n| Week 2 | Germination | Germination check + gap filling | | |\n| Week 3–4 | Seedling | 1st weeding + pest monitoring | | |\n| Week 5–6 | Vegetative | Top-dress fertilizer + irrigation | | |\n| Week 7–8 | Flowering | Foliar spray + flower monitoring | | |\n| Week 9–10 | Pod/grain fill | Irrigation + second pest check | | |\n| Week 11–12 | Maturity | Harvest preparation | | |\n\n## ✅ WEEKLY ACTION CHECKLIST\n\n**Week 1–2**\n- [ ] Seed treatment completed\n- [ ] Sowing finished\n- [ ] Germination checked (Day 5–7)\n- [ ] Gap filling done\n\n**Week 3–4**\n- [ ] First weeding completed\n- [ ] Pest monitoring done (check every 3 days)\n- [ ] Basal fertilizer applied\n\n**Week 5–6**\n- [ ] Top-dress fertilizer applied\n- [ ] Irrigation schedule followed\n- [ ] Sticky traps / monitoring installed\n\n**Week 7–8**\n- [ ] Flowering monitoring (no flower drop)\n- [ ] Foliar spray applied if needed\n- [ ] Pest threshold checked\n\n**Week 9–12**\n- [ ] Pod development checked\n- [ ] Final irrigation before harvest\n- [ ] Harvest equipment arranged\n- [ ] Storage/buyer arranged\n\n## 🏭 CRITICAL MILESTONES\n\n5 most important dates/stages not to miss for {confirmed_crop}:\n1. [Date/week] – [Milestone]\n2. [Date/week] – [Milestone]\n3. [Date/week] – [Milestone]\n4. [Date/week] – [Milestone]\n5. [Date/week] – [Milestone]\n\n## 👷 LABOR PLAN\n\nLabor requirement by month:\n\n| Month | Task | Workers Needed/Day | Total Days |\n|-------|------|--------------------|----------|\n| | | | |\n\n**With {labor_available}:** Is this feasible? What tasks should be mechanized?\n\n## 🏛️ LOCAL SUPPORT\n\nKVK (Krishi Vigyan Kendra) contact for {state} / {city}\nAgriculture department helpline for {state}"
    ],
    [
        "num"   => 9,
        "emoji" => "📦",
        "title" => "Post-Harvest Management",
        "subtitle" => "Storage, transport and reducing losses",
        "color" => "#37474f",
        "fields" => [
            ["name" => "transport_mode", "label" => "Transport to market", "type" => "select","required" => false,
             "options" => ["Own vehicle", "Hired truck/tempo", "FPO/cooperative transport", "Rail (for bulk)", "Depends on buyer"],
             "hint" => "Check availability of 'Kisan Rail' for low-cost bulk transport."],
            ["name" => "packaging_type", "label" => "Packaging used", "type" => "select","required" => false,
             "options" => ["Jute bags", "PP woven bags", "Loose/bulk", "Crates (for perishables)", "Cardboard (for premium market)"],
             "hint" => "Jute bags are preferred for pulses/grain to allow air circulation."],
            ["name" => "processing_interest","label" => "Interested in value addition?","type"=>"select","required"=>false,
             "options" => ["No – sell raw", "Yes – basic cleaning/grading", "Yes – processing (flour, oil etc.)", "Yes – organic certification"],
             "hint" => "Cleaning/Grading can increase your price by ₹200–500 per quintal."],
        ],
        "gemini_key" => "postharvest_plan",
        "prompt" => "You are a post-harvest management expert for Indian agriculture.\n\nFARM SUMMARY:\n- Crop: {confirmed_crop}\n- Location: {city}, {state}\n- Expected yield: {expected_harvest_qty} quintals | Harvest: {harvest_month}\n- Storage: {storage_facility}\n- Transport: {transport_mode}\n- Packaging: {packaging_type}\n- Value addition interest: {processing_interest}\n\nProvide practical post-harvest guidance:\n\n## 🌾 HARVEST TECHNIQUE\n\nCorrect harvesting method for {confirmed_crop} to minimize losses. Best time of day/moisture content.\n\n## 📉 POST-HARVEST LOSS PREVENTION\n\nMain causes of loss for {confirmed_crop} and prevention:\n\n| Loss Source | % If Ignored | Prevention |\n|------------|------------|----------|\n| Improper threshing | | |\n| Poor storage (moisture) | | |\n| Pest in storage | | |\n| Transport damage | | |\n\n**Target: Reduce total post-harvest losses to < 5%** (Indian average is 20–30%)\n\n## 🏪 STORAGE GUIDE\n\n| Parameter | Requirement for {confirmed_crop} |\n|-----------|----------------------------------|\n| Temperature | |\n| Humidity | |\n| Max storage duration | |\n| Treatment (fumigation) | |\n\nNearest warehouse / cold storage in {state}: [name + contact]\nGovernment warehousing: eNWR (Electronic Negotiable Warehouse Receipt) scheme.\n\n## 📦 PACKAGING & GRADING\n\nBest packaging for {confirmed_crop} sold via {sell_preference}.\n\n**AGMARK Grading Standards for {confirmed_crop}:**\n- Grade A: [specs]\n- Grade B: [specs]\nGrading can increase price by ₹X–Y per quintal.\n\n## ✨ VALUE ADDITION\n\n{processing_interest} steps with estimated cost and revenue boost:\n- Investment: ₹X\n- Revenue increase: ₹X–Y per quintal\n- ROI of value addition: X%\n\n## 🚛 TRANSPORT OPTIMIZATION\n\nHow to reduce transport cost from {city} to {nearest_mandi}.\nKisan Rail option for bulk transport.\n\n## 🏛️ GOVERNMENT SCHEMES\n\nWarehousing schemes (eNWR, WDRA) and cold chain subsidies available in {state}.\n\n## 🚨 EMERGENCY ACTION GUIDE\n\n| Problem | Immediate Action |\n|---------|------------------|\n| Leaves turning yellow (standing crop) | Check irrigation + apply micronutrients |\n| Flower drop | Immediate light irrigation |\n| Heavy rain forecast | Improve field drainage + delay spray |\n| Sudden pest attack | Spot spray affected area only (not whole field) |\n| Grain stored getting hot/wet | Open bags + dry in sun + fumigate |"
    ],
    [
        "num"   => 10,
        "emoji" => "💵",
        "title" => "Profit & Cost Planning",
        "subtitle" => "Full farm economics and final recommendations",
        "color" => "#c62828",
        "fields" => [
            ["name" => "actual_seed_cost", "label" => "Actual seed cost spent (₹)","type"=>"number","required"=>false,"placeholder"=>"From Stage 2 estimate", "hint" => "Look at your Stage 2 AI guidance for approximate seed costs."],
            ["name" => "actual_fert_cost", "label" => "Fertilizer cost (₹)", "type"=>"number","required"=>false,"placeholder"=>"From Stage 3 estimate", "hint" => "Look at your Stage 3 AI guidance for fertilizer budget."],
            ["name" => "labour_cost_budget", "label" => "Labor budget (₹)", "type"=>"number","required"=>false,"placeholder"=>"e.g. 15000", "hint" => "Include family labor value or only payment to hired workers."],
            ["name" => "other_costs", "label" => "Other costs (₹) – irrigation, pesticide etc.","type"=>"number","required"=>false,"placeholder"=>"e.g. 10000", "hint" => "Include fuel, electricity, diesel, and maintenance costs."],
            ["name" => "target_profit", "label" => "Target profit (₹)", "type"=>"number","required"=>false,"placeholder"=>"e.g. 80000", "hint" => "What is the net profit you hope to make after all expenses?"],
        ],
        "gemini_key" => "profit_plan",
        "prompt" => "You are a farm economist and financial advisor for Indian farmers.\n\nCOMPLETE 10-STAGE FARM DATA:\n- Farmer: {farmer_name}, {city}, {state}\n- Crop: {confirmed_crop}\n- Land: {land_area} acres | Season: {season}\n- Total Budget: ₹{budget} | Target profit: ₹{target_profit}\n- Seed cost: ₹{actual_seed_cost}\n- Fertilizer cost: ₹{actual_fert_cost}\n- Labor cost: ₹{labour_cost_budget}\n- Other costs (irrigation/pesticide): ₹{other_costs}\n- Expected yield: {expected_harvest_qty} quintals\n- Harvest month: {harvest_month}\n\nCreate a complete farm profit-loss statement and final advisory:\n\n## 💰 COMPLETE COST BREAKDOWN\n\n| Cost Item | ₹/Acre | Total (₹ for {land_area} Acres) |\n|-----------|--------|----------------------------------|\n| Land preparation | | |\n| Seeds | | |\n| Fertilizer (basal) | | |\n| Fertilizer (top-dress) | | |\n| Irrigation / Electricity | | |\n| Labor | | |\n| Pesticides / IPM | | |\n| Harvest & threshing | | |\n| Transport to mandi | | |\n| Packaging | | |\n| Miscellaneous | | |\n| **Total Cost** | | **₹X** |\n| **Contingency Reserve (10%)** | | **₹X** |\n| **Total with Contingency** | | **₹X** |\n\n**Note:** Always keep 10% contingency fund — actual costs can vary by ₹X due to water expenses, extra pesticide, or labor variation.\n\n## 📊 PROFIT SCENARIOS\n\n| Scenario | Yield (Qtl) | Market Price | Revenue | Net Profit | ROI |\n|----------|------------|-------------|---------|------------|-----|\n| Poor season | | ₹X/qtl | | | |\n| Normal season | | ₹X/qtl | | | |\n| Good management | | ₹X/qtl | | | |\n\n**Reality Check:** An ROI above 40% is possible but optimistic. Normal-season ROI of 20–30% is more realistic for {state} conditions.\n\n## 💵 BREAKEVEN ANALYSIS\n\n| Metric | Value |\n|--------|-------|\n| Total cost | ₹X |\n| Minimum price to break even | ₹X/quintal |\n| Minimum yield needed to break even | X quintals |\n\n## 📅 CASHFLOW TIMELINE\n\n| Month | Money Out | Money In | Running Balance |\n|-------|----------|----------|----------------|\n| Month 1 (sowing) | | | |\n| Month 2 | | | |\n| Month 3 | | | |\n| Harvest month | | Revenue | |\n\n## 🏦 LOAN ADVICE\n\nIf budget is tight (₹{budget}): KCC (Kisan Credit Card) or NABARD loan options in {state}.\n\n## 🔑 PRACTICAL RECOMMENDATIONS\n\n1. Verify seed variety at local KVK {city} before purchasing\n2. Check real-time mandi prices before finalising profit plan\n3. Confirm borewell water availability before sowing\n4. Avoid depending fully on {expected_harvest_qty} qtl yield assumption — plan for a 20% lower scenario\n5. Keep 10% contingency fund (₹{budget} × 0.10 = ₹X)\n6. Reduce fertilizer slightly if budget is already stretched\n\n## 📊 FINAL TECHNICAL EVALUATION\n\n| Category | Accuracy Level | Notes |\n|----------|--------------|-------|\n| Agronomy | ⭐⭐⭐⭐☆ | Scientifically sound |\n| Soil & Nutrients | ⭐⭐⭐⭐☆ | Test-dependent |\n| Pest Management | ⭐⭐⭐⭐☆ | IPM based |\n| Weather Forecast | ⭐⭐⭐☆☆ | Probabilistic only |\n| Market Forecast | ⭐⭐⭐☆☆ | Uncertain without live data |\n| Financial Planning | ⭐⭐⭐☆☆ | Optimistic — add contingency |\n\n**Overall Reliability: 7.5 / 10**\n\n## ✅ FINAL VERDICT\n\n**Is this farm plan VIABLE?** [YES / WITH CONDITIONS / HIGH RISK]\n**Risk Level:** LOW / MEDIUM / HIGH\n**Confidence Score:** X/10\n\nThis advisory is:\n✔ Well structured and scientifically aligned\n✔ Regionally adapted to {state} conditions\n✔ Useful for planning and decision-making\n\nHowever:\n✖ Not a guaranteed outcome — farming has natural risks\n✖ Not based on real-time market data — verify on Agmarknet before selling\n✖ Not a substitute for advice from local agronomist or KVK\n\n**Final Executive Summary:** [Write a 3–4 line comprehensive summary of the complete 10-stage farm plan for {farmer_name} in {city}, {state} — covering crop choice, key risks, expected profit range, and top 2 action items.]"
    ],
];
?>
