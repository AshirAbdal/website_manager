<?php
// api/api.php

// Enable error reporting for debugging (remove or comment out in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Include database configuration
include 'db_config.php';

// Get the HTTP method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Read input data
$input = json_decode(file_get_contents('php://input'), true);

// Routing based on 'action' parameter
switch($action) {
    case 'scrape':
        handleScrape($method, $input, $conn);
        break;
    case 'urls':
        handleUrls($method, $input, $conn);
        break;
    case 'pages':
        handlePages($method, $input, $conn);
        break;
    case 'meta_tags':
        handleMetaTags($method, $input, $conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(["message" => "Invalid API endpoint."]);
        break;
}

// Close the database connection
$conn->close();

/**
 * Function to handle Scraping
 */
function handleScrape($method, $input, $conn) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed. Use POST."]);
        exit;
    }

    if (!isset($input['url'])) {
        http_response_code(400);
        echo json_encode(["message" => "URL is required for scraping."]);
        exit;
    }

    $url = filter_var($input['url'], FILTER_VALIDATE_URL);
    if (!$url) {
        http_response_code(400);
        echo json_encode(["message" => "Invalid URL provided."]);
        exit;
    }

    // Parse the URL
    $parsed_url = parse_url($url);
    $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
    $port = isset($parsed_url['port']) ? $parsed_url['port'] : (($parsed_url['scheme'] === 'https') ? 443 : 80);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
    $query_string = isset($parsed_url['query']) ? $parsed_url['query'] : NULL;
    $fragment = isset($parsed_url['fragment']) ? $parsed_url['fragment'] : NULL;

    // Fetch the HTML content using cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Optionally, set a user agent
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; WebsiteScraper/1.0)');
    // Follow redirects
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // Timeout settings
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        http_response_code(500);
        echo json_encode(["message" => "Error fetching the URL: " . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 400) {
        http_response_code($http_code);
        echo json_encode(["message" => "Failed to fetch the URL. HTTP Status Code: " . $http_code]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    // Parse the HTML using DOMDocument
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    // Extract Title
    $title = '';
    $title_tags = $dom->getElementsByTagName('title');
    if ($title_tags->length > 0) {
        $title = trim($title_tags->item(0)->nodeValue);
    }

    // Extract H1
    $h1 = '';
    $h1_tags = $dom->getElementsByTagName('h1');
    if ($h1_tags->length > 0) {
        $h1 = trim($h1_tags->item(0)->nodeValue);
    }

    // Generate Slug from Title if not provided
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));

    // Extract Description from meta tag
    $description = '';
    $meta_tags = $dom->getElementsByTagName('meta');
    foreach ($meta_tags as $meta) {
        if ($meta instanceof DOMElement) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $description = trim($meta->getAttribute('content'));
                break;
            }
        }
    }

    // Extract other meta tags as needed
    $meta_title = $title;
    $meta_description = $description;
    $viewpoint = ''; // Custom extraction as needed
    $author = ''; // Custom extraction as needed
    $twitter_card_tags = ''; // Custom extraction as needed
    $language_tag = 'en'; // Default or extract from html lang attribute

    // Extract language from HTML tag
    $html_tags = $dom->getElementsByTagName('html');
    if ($html_tags->length > 0) {
        $lang = $html_tags->item(0)->getAttribute('lang');
        if (!empty($lang)) {
            $language_tag = substr($lang, 0, 2); // e.g., 'en', 'de'
        }
    }

    // Extract global scripts if needed (e.g., inline scripts)
    $global_script = '';
    $script_tags = $dom->getElementsByTagName('script');
    foreach ($script_tags as $script) {
        if ($script instanceof DOMElement) {
            if (!$script->hasAttribute('src')) { // Inline scripts
                $global_script .= trim($script->nodeValue) . "\n";
            }
        }
    }

    // Extract canonical URL
    $canonical_url = '';
    foreach ($meta_tags as $meta) {
        if ($meta instanceof DOMElement) {
            if (strtolower($meta->getAttribute('rel')) === 'canonical') {
                $canonical_url = trim($meta->getAttribute('href'));
                break;
            }
        }
    }

    // Extract schema markup (JSON-LD)
    $schema_markup = '';
    foreach ($script_tags as $script) {
        if ($script instanceof DOMElement) {
            if ($script->getAttribute('type') === 'application/ld+json') {
                $schema_markup .= trim($script->nodeValue) . "\n";
            }
        }
    }

    // Begin Database Transactions
    $conn->begin_transaction();

    try {
        // Insert into Url table
        $stmt = $conn->prepare("INSERT INTO Url (host, port, path, query_string, fragment) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $stmt->bind_param("sisss", $host, $port, $path, $query_string, $fragment);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $url_id = $stmt->insert_id;
        $stmt->close();

        // Insert into Page table
        $stmt = $conn->prepare("INSERT INTO Page (url_id, title, H1, slug, description, p_after_h1, global_script, canonical_url, schema_markup) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $p_after_h1 = NULL; // Modify if you have specific data
        $stmt->bind_param("issssssss", $url_id, $title, $h1, $slug, $description, $p_after_h1, $global_script, $canonical_url, $schema_markup);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $page_id = $stmt->insert_id;
        $stmt->close();

        // Insert into meta_tags table
        $stmt = $conn->prepare("INSERT INTO meta_tags (page_id, meta_title, meta_description, viewpoint, author, twitter_card_tags, language_tag) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $stmt->bind_param("issssss", $page_id, $meta_title, $meta_description, $viewpoint, $author, $twitter_card_tags, $language_tag);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $meta_tag_id = $stmt->insert_id;
        $stmt->close();

        // Commit the transaction
        $conn->commit();

        // Return success response
        echo json_encode([
            "message" => "URL scraped and data stored successfully.",
            "url_id" => $url_id,
            "page_id" => $page_id,
            "meta_tag_id" => $meta_tag_id
        ]);
    } catch (Exception $e) {
        // Rollback the transaction on error
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["message" => "Error storing data: " . $e->getMessage()]);
    }
}

/**
 * Function to handle URL operations
 */
function handleUrls($method, $input, $conn) {
    switch($method) {
        case 'GET':
            // Retrieve all URLs
            $sql = "SELECT * FROM Url";
            $result = $conn->query($sql);
            $urls = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $urls[] = $row;
                }
            }
            echo json_encode($urls);
            break;
        
        case 'POST':
            // Create a new URL (Manual addition if needed)
            // Implementation can be similar to handleScrape without scraping
            // For brevity, this is omitted. Implement as needed.
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'PUT':
            // Update an existing URL
            // Implementation omitted for brevity
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'DELETE':
            // Delete a URL
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(["message" => "URL ID is required for deletion."]);
                exit;
            }
            $id = intval($_GET['id']);
            $sql = "DELETE FROM Url WHERE id=$id";
            if ($conn->query($sql) === TRUE) {
                echo json_encode(["message" => "URL deleted successfully."]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Error deleting URL: " . $conn->error]);
            }
            break;
        
        default:
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed."]);
            break;
    }
}

/**
 * Function to handle Page operations
 */
function handlePages($method, $input, $conn) {
    switch($method) {
        case 'GET':
            // Retrieve all Pages
            $sql = "SELECT * FROM Page";
            $result = $conn->query($sql);
            $pages = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $pages[] = $row;
                }
            }
            echo json_encode($pages);
            break;
        
        case 'POST':
            // Create a new Page (Manual addition if needed)
            // Implementation can be similar to handleScrape without scraping
            // For brevity, this is omitted. Implement as needed.
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'PUT':
            // Update an existing Page
            // Implementation omitted for brevity
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'DELETE':
            // Delete a Page
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(["message" => "Page ID is required for deletion."]);
                exit;
            }
            $id = intval($_GET['id']);
            $sql = "DELETE FROM Page WHERE id=$id";
            if ($conn->query($sql) === TRUE) {
                echo json_encode(["message" => "Page deleted successfully."]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Error deleting Page: " . $conn->error]);
            }
            break;
        
        default:
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed."]);
            break;
    }
}

/**
 * Function to handle Meta Tags operations
 */
function handleMetaTags($method, $input, $conn) {
    switch($method) {
        case 'GET':
            // Retrieve all Meta Tags
            $sql = "SELECT * FROM meta_tags";
            $result = $conn->query($sql);
            $meta_tags = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $meta_tags[] = $row;
                }
            }
            echo json_encode($meta_tags);
            break;
        
        case 'POST':
            // Create a new Meta Tag (Manual addition if needed)
            // Implementation can be similar to handleScrape without scraping
            // For brevity, this is omitted. Implement as needed.
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'PUT':
            // Update an existing Meta Tag
            // Implementation omitted for brevity
            http_response_code(501);
            echo json_encode(["message" => "Not Implemented."]);
            break;
        
        case 'DELETE':
            // Delete a Meta Tag
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(["message" => "Meta Tag ID is required for deletion."]);
                exit;
            }
            $id = intval($_GET['id']);
            $sql = "DELETE FROM meta_tags WHERE id=$id";
            if ($conn->query($sql) === TRUE) {
                echo json_encode(["message" => "Meta Tag deleted successfully."]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Error deleting Meta Tag: " . $conn->error]);
            }
            break;
        
        default:
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed."]);
            break;
    }
}
?>
