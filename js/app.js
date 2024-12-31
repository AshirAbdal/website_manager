// js/app.js
var app = angular.module('websiteScraperApp', []);

app.controller('MainController', ['$scope', '$http', function($scope, $http) {
    $scope.currentTab = 'urls'; // Default tab
    $scope.urls = [];
    $scope.pages = [];
    $scope.meta_tags = [];

    $scope.newScrape = {};
    $scope.urlToEdit = null;
    $scope.pageToEdit = null;
    $scope.metaTagToEdit = null;

    $scope.scrapeStatus = '';

    // Set the current tab
    $scope.setTab = function(tab) {
        $scope.currentTab = tab;
    };

    // Fetch all URLs
    $scope.fetchUrls = function() {
        $http.get('api/api.php?action=urls')
            .then(function(response) {
                $scope.urls = response.data;
            }, function(error) {
                console.error('Error fetching URLs:', error);
            });
    };

    // Fetch all Pages
    $scope.fetchPages = function() {
        $http.get('api/api.php?action=pages')
            .then(function(response) {
                $scope.pages = response.data;
            }, function(error) {
                console.error('Error fetching Pages:', error);
            });
    };

    // Fetch all Meta Tags
    $scope.fetchMetaTags = function() {
        $http.get('api/api.php?action=meta_tags')
            .then(function(response) {
                $scope.meta_tags = response.data;
            }, function(error) {
                console.error('Error fetching Meta Tags:', error);
            });
    };

    // Initialize by fetching all data
    $scope.init = function() {
        $scope.fetchUrls();
        $scope.fetchPages();
        $scope.fetchMetaTags();
    };

    $scope.init();

    // Utility functions to get related data
    $scope.getUrl = function(url_id) {
        var url = $scope.urls.find(u => u.id === parseInt(url_id));
        return url ? url.host + ':' + url.port + url.path : 'N/A';
    };

    $scope.getPage = function(page_id) {
        var page = $scope.pages.find(p => p.id === parseInt(page_id));
        return page ? page.title : 'N/A';
    };

    // Scrape URL
    $scope.scrapeUrl = function() {
        if (!$scope.newScrape.url) {
            alert("Please enter a valid URL.");
            return;
        }

        $scope.scrapeStatus = "Scraping in progress...";

        $http.post('api/api.php?action=scrape', { url: $scope.newScrape.url })
            .then(function(response) {
                $scope.scrapeStatus = "Scraping completed successfully.";
                $scope.newScrape = {};
                $scope.fetchUrls();
                $scope.fetchPages();
                $scope.fetchMetaTags();
            }, function(error) {
                $scope.scrapeStatus = "Error during scraping: " + (error.data.message || "Unknown error.");
                console.error('Error scraping URL:', error);
            });
    };

    // URL Operations (Edit & Delete)
    $scope.editUrl = function(url) {
        $scope.urlToEdit = angular.copy(url);
    };

    $scope.updateUrl = function() {
        $http.put('api/api.php?action=urls', { url: $scope.urlToEdit })
            .then(function(response) {
                $scope.fetchUrls();
                $scope.urlToEdit = null; // Close modal
            }, function(error) {
                alert("Error updating URL: " + (error.data.message || "Unknown error."));
                console.error('Error updating URL:', error);
            });
    };

    $scope.deleteUrl = function(id) {
        if (confirm("Are you sure you want to delete this URL? This will also delete associated Pages and Meta Tags.")) {
            $http({
                method: 'DELETE',
                url: 'api/api.php?action=urls&id=' + id
            }).then(function(response) {
                $scope.fetchUrls();
                $scope.fetchPages();
                $scope.fetchMetaTags();
            }, function(error) {
                alert("Error deleting URL: " + (error.data.message || "Unknown error."));
                console.error('Error deleting URL:', error);
            });
        }
    };

    // Page Operations (Edit & Delete)
    $scope.editPage = function(page) {
        $scope.pageToEdit = angular.copy(page);
    };

    $scope.updatePage = function() {
        $http.put('api/api.php?action=pages', { page: $scope.pageToEdit })
            .then(function(response) {
                $scope.fetchPages();
                $scope.pageToEdit = null; // Close modal
            }, function(error) {
                alert("Error updating Page: " + (error.data.message || "Unknown error."));
                console.error('Error updating Page:', error);
            });
    };

    $scope.deletePage = function(id) {
        if (confirm("Are you sure you want to delete this Page? This will also delete associated Meta Tags.")) {
            $http({
                method: 'DELETE',
                url: 'api/api.php?action=pages&id=' + id
            }).then(function(response) {
                $scope.fetchPages();
                $scope.fetchMetaTags();
            }, function(error) {
                alert("Error deleting Page: " + (error.data.message || "Unknown error."));
                console.error('Error deleting Page:', error);
            });
        }
    };

    // Meta Tags Operations (Edit & Delete)
    $scope.editMetaTag = function(meta) {
        $scope.metaTagToEdit = angular.copy(meta);
    };

    $scope.updateMetaTag = function() {
        $http.put('api/api.php?action=meta_tags', { meta_tag: $scope.metaTagToEdit })
            .then(function(response) {
                $scope.fetchMetaTags();
                $scope.metaTagToEdit = null; // Close modal
            }, function(error) {
                alert("Error updating Meta Tag: " + (error.data.message || "Unknown error."));
                console.error('Error updating Meta Tag:', error);
            });
    };

    $scope.deleteMetaTag = function(id) {
        if (confirm("Are you sure you want to delete this Meta Tag?")) {
            $http({
                method: 'DELETE',
                url: 'api/api.php?action=meta_tags&id=' + id
            }).then(function(response) {
                $scope.fetchMetaTags();
            }, function(error) {
                alert("Error deleting Meta Tag: " + (error.data.message || "Unknown error."));
                console.error('Error deleting Meta Tag:', error);
            });
        }
    };
}]);
