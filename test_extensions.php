<?php
/**
 * Extension Test File
 * This file tests all VS Code extensions
 * 
 * @test PHP Intelephense - autocomplete, hover, go-to-definition
 * @test GitLens - git blame, history
 * @test EditorConfig - formatting (4 spaces)
 * @test GitHub Copilot - AI suggestions
 */

namespace App\Test;

class ExtensionTest
{
    private $database;
    private $config;
    
    /**
     * Constructor
     * 
     * TEST: Hover over __construct to see signature
     * TEST: Type $this-> to see autocomplete
     */
    public function __construct()
    {
        $this->database = null;
        $this->config = [];
    }
    
    /**
     * Test method for Intelephense
     * 
     * TEST: Ctrl+Click on testIntelephense to go to definition
     * TEST: Hover to see PHPDoc
     */
    public function testIntelephense(): bool
    {
        // TEST: Type 'str' and wait for autocomplete
        $test = strlen("Hello World");
        
        // TEST: Hover over $test to see type
        return $test > 0;
    }
    
    /**
     * Test GitLens
     * 
     * TEST: Hover over any line to see git blame
     * TEST: Look at the left gutter for git changes
     */
    public function testGitLens(): void
    {
        // This line should show who committed it
        $data = "GitLens test";
        
        // GitLens should show inline blame
        echo $data;
    }
    
    /**
     * Test EditorConfig
     * 
     * TEST: Press Tab - should insert 4 spaces (not a tab)
     * TEST: New line should maintain indentation
     */
    public function testEditorConfig(): void
    {
        if (true) {
            // This should be indented with 4 spaces
            $indented = "test";
            
            if ($indented) {
                // This should be 8 spaces (2 levels)
                echo $indented;
            }
        }
    }
    
    /**
     * Test Copilot
     * 
     * TEST: Start typing a comment like "// calculate sum" 
     * TEST: Copilot should suggest code
     */
    public function testCopilot(): int
    {
        // calculate the sum of two numbers
        
        // Copilot should suggest something here
        return 0;
    }
}
