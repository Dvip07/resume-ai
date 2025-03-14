<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ResumeUpload;
use App\Models\UserProfile;

// class AnalyzeResumeJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     protected $resume;
//     protected $resumeText;

//     public function __construct(ResumeUpload $resume, $resumeText)
//     {
//         $this->resume = $resume;
//         $this->resumeText = $resumeText;
//     }

//     public function handle()
//     {
//         $ollamaUrl = 'http://localhost:5001/llm'; // Flask API
//         $prompt = "Extract key details and analyze the resume.";

//         // Debug: Check if the resume text is reaching the job
//         Log::debug("AnalyzeResumeJob: Received resume text: " . $this->resumeText);

//         try {
//             $response = Http::timeout(300)->post($ollamaUrl, [
//                 'text' => json_encode([
//                     'prompt' => $prompt,
//                     'resume_text' => $this->resumeText,
//                 ]),
//             ]);

//             // Debug: Log the raw response body from the API call
//             Log::debug("AnalyzeResumeJob: Ollama API response: " . $response->body());

//             if ($response->successful()) {
//                 $result = $response->json();
//                 // Debug: Log the parsed data received from the API
//                 Log::debug("AnalyzeResumeJob: Parsed data: " . print_r($result, true));

//                 $this->resume->update([
//                     'parsed_data' => $result,
//                     'is_optimized' => true,
//                 ]);
//             } else {
//                 Log::error("AnalyzeResumeJob: Unsuccessful response: " . $response->body());
//             }
//         } catch (\Exception $e) {
//             Log::error("Ollama API Error: " . $e->getMessage());
//         }
//     }
// }



// class AnalyzeResumeJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     private $resumeUpload;
//     private $resumeText;


//     public function __construct(ResumeUpload $resumeUpload, string $resumeText)
//     {
//         // dd($resumeText);
//         $this->resumeUpload = $resumeUpload;
//         $this->resumeText = $resumeText;
//     }

//     public function handle()
//     {
//         try {
//             // Send resume text to Ollama API for analysis
//             $ollamaResponse = Http::timeout(120)
//                 ->post('http://localhost:5001/llm', [
//                     'prompt' => $this->createAnalysisPrompt(),
//                     'resume_text' => $this->resumeText,
//                 ]);

//             if ($ollamaResponse->successful()) {
//                 // Process the response from Ollama API
//                 $parsedData = $this->processOllamaResponse($ollamaResponse->json());

//                 // Update `user_profiles` table with extracted data
//                 $this->updateUserProfile($parsedData);

//                 // Update `resume_uploads` table with parsed data
//                 $this->resumeUpload->update([
//                     'parsed_data' => $parsedData,
//                 ]);

//                 Log::info("Resume analysis completed for Resume ID: " . $this->resumeUpload->id);
//             } else {
//                 Log::error("Ollama API Error: " . $ollamaResponse->body());
//             }
//         } catch (\Exception $e) {
//             Log::error("Resume Analysis Failed: " . $e->getMessage());
//         }
//     }

//     private function createAnalysisPrompt(): string
//     {
//         return <<<PROMPT
//     Analyze the following resume and extract the following details in JSON format:
//     1. A 2-3 sentence professional summary.
//     2. Skills categorized into "primary" and "secondary".
//     3. Work experience with details: company name, position, duration in months, and achievements.
//     4. Education details: institution name, degree, field of study, and years attended.
//     5. A list of keywords relevant to the resume.

//     Resume Text:
//     PROMPT;
//     }


//     private function processOllamaResponse(array $response): array
//     {
//         try {
//             return json_decode($response['response'] ?? '{}', true);
//         } catch (\Exception $e) {
//             Log::warning("Failed to parse Ollama response: " . $e->getMessage());
//             return [];
//         }
//     }

//     private function updateUserProfile(array $parsedData): void
//     {
//         // Update or create user profile based on the parsed data
//         UserProfile::updateOrCreate(
//             ['user_id' => $this->resumeUpload->user_id],
//             [
//                 'skills' => [
//                     'primary' => $parsedData['skills']['primary'] ?? [],
//                     'secondary' => $parsedData['skills']['secondary'] ?? [],
//                 ],
//                 'experience' => $parsedData['experience'] ?? [],
//                 'education' => $parsedData['education'] ?? [],
//                 'parsed_keywords' => implode(',', ($parsedData['keywords'] ?? [])),
//                 'resume_text' => $this->resumeText,
//             ]
//         );
//     }
// }

class AnalyzeResumeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $resumeUpload;
    private $resumeText;

    public function __construct(ResumeUpload $resumeUpload, string $resumeText)
    {
        $this->resumeUpload = $resumeUpload;
        $this->resumeText = $resumeText;
    }

    public function handle()
    {
        try {
            // Combine prompt and resume text into a single field
            // $prompt = "Extract key details and analyze the resume. Return the following details in JSON format:\n\n" .
            //     "1. Skills categorized into 'primary' and 'secondary'.\n" .
            //     "2. Work experience with details: company name, position, duration in months.\n" .
            //     "3. Education details: institution name, degree, field of study, and years attended.\n" .
            //     "4. A list of keywords relevant to the resume.\n\n" .
            //     "5. A list of Suggested Job roles based on the resume.\n\n" .
            //     "Resume Text:\n" . $this->resumeText;

            $prompt = "Extract key details and analyze the resume. Return the following details in JSON format:\n\n" .
                "1. Skills in a 'Skills' object with 'Primary' and 'Secondary' arrays.\n" .
                "2. Work experience in a 'workExperience' array with objects containing: 'companyName', 'position', 'duration' (in months), and 'achievements' array.\n" .
                "3. Education details in an 'education' object with 'institution' array, 'degree' array, 'fieldOfStudy' array, and 'yearsAttended' array of objects with 'startYear' and 'endYear'.\n" .
                "4. A 'keywords' array with relevant terms from the resume.\n" .
                "5. A 'summary' field with a 2-3 sentence professional summary.\n" .
                "6. A 'suggestedJobRoles' array with relevant job roles based on the resume, concatenated into one line.\n" .
                "7. A 'location' object with keys 'city', 'state', and 'country' if available.\n" .
                "8. 'linkedin_url', 'github_url', and 'portfolio_url' as separate fields if available.\n\n" .
                "Resume Text:\n" . $this->resumeText;



            // Send the request to Ollama API
            $ollamaResponse = Http::timeout(300)->post('http://localhost:5001/llm', [
                'prompt' => $prompt,
                'resume_text' => $this->resumeText,
            ]);

            if ($ollamaResponse->successful()) {
                // Process the response from Ollama API
                $parsedData = $this->processOllamaResponse($ollamaResponse->json());

                // Update `user_profiles` table with extracted data
                $this->updateUserProfile($parsedData);

                // Update `resume_uploads` table with parsed data
                $this->resumeUpload->update([
                    'parsed_data' => $parsedData,
                ]);

                Log::info("Resume analysis completed for Resume ID: " . $this->resumeUpload->id);
            } else {
                Log::error("Ollama API Error: " . $ollamaResponse->body());
            }
        } catch (\Exception $e) {
            Log::error("Resume Analysis Failed: " . $e->getMessage());
        }
    }

    // private function processOllamaResponse(array $response): array
    // {
    //     try {
    //         return json_decode($response['response'] ?? '{}', true);
    //     } catch (\Exception $e) {
    //         Log::warning("Failed to parse Ollama response: " . $e->getMessage());
    //         return [];
    //     }
    // }

    private function processOllamaResponse(array $response): array
    {
        try {
            // Check if we have a response
            if (!isset($response['response'])) {
                Log::warning("No 'response' key in Ollama response");
                return [];
            }

            $responseText = $response['response'];
            Log::info("Raw response text: " . substr($responseText, 0, 200000) . "...");

            // Extract JSON from markdown format if present
            if (preg_match('/```json\s*(.*?)\s*```/s', $responseText, $matches)) {
                $jsonString = $matches[1];
            } else {
                // If no markdown format, try to find JSON objects
                if (preg_match('/(\{.*\})/s', $responseText, $matches)) {
                    $jsonString = $matches[0];
                } else {
                    // If no JSON found, return empty array
                    Log::warning("No JSON found in response: " . substr($responseText, 0, 100) . "...");
                    return [];
                }
            }

            // Now decode the extracted JSON
            $decoded = json_decode($jsonString, true);
            Log::info("Decoded JSON: " . json_encode($decoded));

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("JSON decode error: " . json_last_error_msg() . " in: " . substr($jsonString, 0, 100) . "...");
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            Log::warning("Failed to parse Ollama response: " . $e->getMessage());
            return [];
        }
    }

    private function updateUserProfile(array $parsedData): void
    {
        try {
            // Log the entire parsed data for debugging
            Log::info('Parsed data: ' . json_encode($parsedData));

            // Normalize and extract skills data
            if (isset($parsedData['skills'])) {
                $normalizedSkills = array_change_key_case($parsedData['skills'], CASE_LOWER);
                $skills = [
                    'primary' => $normalizedSkills['primary'] ?? [],
                    'secondary' => $normalizedSkills['secondary'] ?? []
                ];
                Log::info('Using skills data from $parsedData[\'skills\']: ' . json_encode($normalizedSkills));
            } elseif (isset($parsedData['Skills'])) {
                $normalizedSkills = array_change_key_case($parsedData['Skills'], CASE_LOWER);
                $skills = [
                    'primary' => $normalizedSkills['primary'] ?? [],
                    'secondary' => $normalizedSkills['secondary'] ?? []
                ];
                Log::info('Using skills data from $parsedData[\'Skills\']: ' . json_encode($normalizedSkills));
            } else {
                Log::warning('No structured skills data found in parsed data');
                $skills = ['primary' => [], 'secondary' => []];
            }

            // Handle work experience
            $experience = [];
            if (isset($parsedData['workExperience'])) {
                foreach ($parsedData['workExperience'] as $work) {
                    // If duration is provided as an array (e.g. {"months": 12}), extract the months
                    $duration = $work['duration'] ?? 0;
                    if (is_array($duration) && isset($duration['months'])) {
                        $duration = $duration['months'];
                    }
                    $experience[] = [
                        'company' => $work['companyName'] ?? '',
                        'position' => $work['position'] ?? '',
                        'duration' => $duration,
                        'achievements' => $work['achievements'] ?? []
                    ];
                }
            } elseif (isset($parsedData['experience'])) {
                $experience = $parsedData['experience'];
            }

            // Map education data
            $education = [];
            if (isset($parsedData['education'])) {
                if (isset($parsedData['education']['institution'])) {
                    for ($i = 0; $i < count($parsedData['education']['institution'] ?? []); $i++) {
                        $education[] = [
                            'institution' => $parsedData['education']['institution'][$i] ?? '',
                            'degree' => $parsedData['education']['degree'][$i] ?? '',
                            'fieldOfStudy' => $parsedData['education']['fieldOfStudy'][$i] ?? '',
                            'startYear' => $parsedData['education']['yearsAttended'][$i]['startYear'] ?? null,
                            'endYear' => $parsedData['education']['yearsAttended'][$i]['endYear'] ?? null
                        ];
                    }
                } elseif (is_array($parsedData['education'])) {
                    $education = $parsedData['education'];
                }
            }

            // Extract suggested job roles (assuming the key is 'suggestedJobRoles' in the response)
            $suggestedRoles = $parsedData['suggestedJobRoles'] ?? [];

            // Extract additional fields with safe defaults:
            $location = $parsedData['location'] ?? [];
            $linkedinUrl = $parsedData['linkedin_url'] ?? '';
            $githubUrl = $parsedData['github_url'] ?? '';
            $portfolioUrl = $parsedData['portfolio_url'] ?? '';

            // Update or create the user profile with all fields
            UserProfile::updateOrCreate(
                ['user_id' => $this->resumeUpload->user_id],
                [
                    'skills' => $skills,
                    'location' => $location,
                    'linkedin_url' => $linkedinUrl,
                    'github_url' => $githubUrl,
                    'portfolio_url' => $portfolioUrl,
                    'suggested_roles' => $suggestedRoles,
                    'experience' => $experience,
                    'education' => $education,
                    'parsed_keywords' => implode(', ', ($parsedData['keywords'] ?? [])),
                    'resume_text' => $this->resumeText,
                ]
            );

            Log::info('User profile updated successfully for user_id: ' . $this->resumeUpload->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to update user profile: ' . $e->getMessage(), [
                'exception' => $e,
                'parsedData' => json_encode($parsedData)
            ]);
        }
    }


    // private function updateUserProfile(array $parsedData): void
    // {
    //     try {
    //         // Log the parsed data for debugging
    //         Log::info('Parsed data: ' . json_encode($parsedData));

    //         // Transform the parsed data to match expected schema structure

    //         // $skills = [];

    //         // try {
    //         //     // Log the parsed data for debugging
    //         //     Log::info('Parsed data: ' . json_encode($parsedData));

    //         //     // Transform the parsed data to match expected schema structure
    //         //     $skills = ['primary' => [], 'secondary' => []];

    //         //     // Check different possible formats of skills data
    //         //     if (isset($parsedData['skills'])) {
    //         //         // Check if 'skills' exists and has the expected structure
    //         //         if (isset($parsedData['skills']['primary'])) {
    //         //             $skills['primary'] = $parsedData['skills']['primary'];
    //         //         }

    //         //         if (isset($parsedData['skills']['secondary'])) {
    //         //             $skills['secondary'] = $parsedData['skills']['secondary'];
    //         //         }

    //         //         Log::info('Using skills data from $parsedData[\'skills\']');
    //         //     } elseif (isset($parsedData['Skills'])) {
    //         //         // Check if 'Skills' (capital S) exists
    //         //         if (isset($parsedData['Skills']['Primary'])) {
    //         //             $skills['primary'] = $parsedData['Skills']['Primary'];
    //         //         }

    //         //         if (isset($parsedData['Skills']['Secondary'])) {
    //         //             $skills['secondary'] = $parsedData['Skills']['Secondary'];
    //         //         }

    //         //         Log::info('Using skills data from $parsedData[\'Skills\']');
    //         //     } else {
    //         //         // If no structured skills data is found, try to extract from keywords
    //         //         Log::warning('No structured skills data found in parsed data');
    //         //         if (isset($parsedData['keywords']) && is_array($parsedData['keywords'])) {
    //         //             $skills['primary'] = $parsedData['keywords'];
    //         //             Log::info('Using keywords as primary skills');
    //         //         }
    //         //     }
    //         // } catch (\Exception $e) {
    //         //     Log::error('Failed to transform skills data: ' . $e->getMessage());
    //         // }

    //         $skills = [
    //             'primary' => $parsedData['skills']['primary'] ?? [],
    //             'secondary' => $parsedData['skills']['secondary'] ?? []
    //         ];

    //         Log::info('Transformed skills data: ' . json_encode($skills));

    //         // Handle work experience - map from workExperience to expected structure
    //         $experience = [];
    //         if (isset($parsedData['workExperience'])) {
    //             foreach ($parsedData['workExperience'] as $work) {
    //                 $experience[] = [
    //                     'company' => $work['companyName'] ?? '',
    //                     'position' => $work['position'] ?? '',
    //                     'duration' => $work['duration'] ?? 0,
    //                     'achievements' => $work['achievements'] ?? []
    //                 ];
    //             }
    //         } else if (isset($parsedData['experience'])) {
    //             $experience = $parsedData['experience'];
    //         }

    //         // Map education data from parsed structure to expected structure
    //         $education = [];
    //         if (isset($parsedData['education'])) {
    //             // The education might be in various formats, so handle different possibilities
    //             if (isset($parsedData['education']['institution'])) {
    //                 // If education has institution as an array
    //                 for ($i = 0; $i < count($parsedData['education']['institution'] ?? []); $i++) {
    //                     $education[] = [
    //                         'institution' => $parsedData['education']['institution'][$i] ?? '',
    //                         'degree' => $parsedData['education']['degree'][$i] ?? '',
    //                         'fieldOfStudy' => $parsedData['education']['fieldOfStudy'][$i] ?? '',
    //                         'startYear' => $parsedData['education']['yearsAttended'][$i]['startYear'] ?? null,
    //                         'endYear' => $parsedData['education']['yearsAttended'][$i]['endYear'] ?? null
    //                     ];
    //                 }
    //             } else if (is_array($parsedData['education'])) {
    //                 // If education is already an array of education objects
    //                 $education = $parsedData['education'];
    //             }
    //         }

    //         UserProfile::updateOrCreate(
    //             ['user_id' => $this->resumeUpload->user_id],
    //             [
    //                 'skills' => $skills,
    //                 'experience' => $experience,
    //                 'education' => $education,
    //                 'parsed_keywords' => implode(', ', ($parsedData['keywords'] ?? [])),
    //                 'resume_text' => $this->resumeText,
    //             ]
    //         );

    //         Log::info('User profile updated successfully for user_id: ' . $this->resumeUpload->user_id);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to update user profile: ' . $e->getMessage(), [
    //             'exception' => $e,
    //             'parsedData' => json_encode($parsedData)
    //         ]);
    //     }
    // }
}


// namespace App\Jobs;

// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;
// use App\Models\ResumeUpload;
// use App\Models\UserProfile;

// class AnalyzeResumeJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     private $resumeUpload;
//     private $resumeText;

//     public function __construct(ResumeUpload $resumeUpload, string $resumeText)
//     {
//         $this->resumeUpload = $resumeUpload;
//         $this->resumeText = $resumeText;
//     }

//     public function handle()
//     {
//         try {
//             // Build the prompt with detailed instructions
//             $prompt = "Extract key details and analyze the resume. Return the following details in JSON format:\n\n" .
//                 "1. Skills in a 'skills' object with 'primary' and 'secondary' arrays.\n" .
//                 "2. Work experience in a 'workExperience' array with objects containing: 'companyName', 'position', 'duration' in months, and 'achievements' array.\n" .
//                 "3. Education details in an 'education' object with 'institution' array, 'degree' array, 'fieldOfStudy' array, and 'yearsAttended' array of objects with 'startYear' and 'endYear'.\n" .
//                 "4. A 'keywords' array with relevant terms from the resume.\n" .
//                 "5. A 'summary' field with a 2-3 sentence professional summary.\n\n" .
//                 "Resume Text:\n" . $this->resumeText;

//             // Log the prompt and resume text being sent
//             Log::info("Sending request to Ollama API with prompt: " . $prompt);

//             // Send the request to the Ollama API
//             $ollamaResponse = Http::timeout(300)->post('http://localhost:5001/llm', [
//                 'text' => $prompt,
//             ]);

//             // Log the raw response from Ollama API (status and body)
//             Log::info("Received response from Ollama API. Status: " . $ollamaResponse->status() . " Body: " . $ollamaResponse->body());

//             if ($ollamaResponse->successful()) {
//                 // Process the response from Ollama API
//                 $parsedData = $this->processOllamaResponse($ollamaResponse->json());
//                 Log::info("Processed Ollama response. Parsed Data: " . json_encode($parsedData));

//                 // Update the user_profiles table with the extracted data
//                 $this->updateUserProfile($parsedData);
//                 Log::info("User profile update process completed.");

//                 // Update the resume_uploads table with the parsed data
//                 $this->resumeUpload->update([
//                     'parsed_data' => $parsedData,
//                 ]);
//                 Log::info("Updated resume_uploads table for Resume ID: " . $this->resumeUpload->id);
//             } else {
//                 Log::error("Ollama API Error: " . $ollamaResponse->body());
//             }
//         } catch (\Exception $e) {
//             Log::error("Resume Analysis Failed: " . $e->getMessage());
//         }
//     }

//     private function processOllamaResponse(array $response): array
//     {
//         try {
//             Log::info("Starting to process Ollama response.");
//             if (!isset($response['response'])) {
//                 Log::warning("No 'response' key in Ollama response");
//                 return [];
//             }

//             $responseText = $response['response'];
//             Log::info("Raw response text: " . substr($responseText, 0, 200) . "...");

//             // Try to extract JSON from markdown format if present
//             if (preg_match('/```json\s*(.*?)\s*```/s', $responseText, $matches)) {
//                 $jsonString = $matches[1];
//                 Log::info("Extracted JSON from markdown format.");
//             } else {
//                 // If no markdown format, try to find JSON object in the response text
//                 if (preg_match('/(\{.*\})/s', $responseText, $matches)) {
//                     $jsonString = $matches[0];
//                     Log::info("Extracted JSON from plain text format.");
//                 } else {
//                     Log::warning("No JSON found in response: " . substr($responseText, 0, 100) . "...");
//                     return [];
//                 }
//             }

//             // Decode the JSON string
//             $decoded = json_decode($jsonString, true);
//             if (json_last_error() !== JSON_ERROR_NONE) {
//                 Log::warning("JSON decode error: " . json_last_error_msg() . " in: " . substr($jsonString, 0, 100) . "...");
//                 return [];
//             }
//             Log::info("Successfully decoded JSON from response.");
//             return is_array($decoded) ? $decoded : [];
//         } catch (\Exception $e) {
//             Log::warning("Failed to process Ollama response: " . $e->getMessage());
//             return [];
//         }
//     }

//     private function updateUserProfile(array $parsedData): void
//     {
//         try {
//             Log::info("Starting user profile update process.");
//             Log::info("Raw parsed data: " . json_encode($parsedData));

//             // Map Skills data from the parsed data (using the response keys from Ollama)
//             $skills = [
//                 'primary' => $parsedData['Skills']['Primary'] ?? [],
//                 'secondary' => $parsedData['Skills']['Secondary'] ?? []
//             ];
//             Log::info("Transformed skills data: " . json_encode($skills));

//             // Map Work Experience data
//             $experience = [];
//             if (isset($parsedData['workExperience'])) {
//                 foreach ($parsedData['workExperience'] as $work) {
//                     $experience[] = [
//                         'company' => $work['companyName'] ?? '',
//                         'position' => $work['position'] ?? '',
//                         'duration' => $work['duration'] ?? 0,
//                         'achievements' => $work['achievements'] ?? []
//                     ];
//                 }
//             } else if (isset($parsedData['WorkExperience'])) {
//                 foreach ($parsedData['WorkExperience'] as $work) {
//                     $experience[] = [
//                         'company' => $work['Company'] ?? '',
//                         'position' => $work['Position'] ?? '',
//                         'duration' => $work['Duration'] ?? 0,
//                         'achievements' => $work['Achievements'] ?? []
//                     ];
//                 }
//             }
//             Log::info("Mapped experience data: " . json_encode($experience));

//             // Map Education data
//             $education = [];
//             if (isset($parsedData['education'])) {
//                 // If education data is in a structured object format
//                 if (isset($parsedData['education']['institution'])) {
//                     for ($i = 0; $i < count($parsedData['education']['institution'] ?? []); $i++) {
//                         $education[] = [
//                             'institution' => $parsedData['education']['institution'][$i] ?? '',
//                             'degree' => $parsedData['education']['degree'][$i] ?? '',
//                             'fieldOfStudy' => $parsedData['education']['fieldOfStudy'][$i] ?? '',
//                             'startYear' => $parsedData['education']['yearsAttended'][$i]['startYear'] ?? null,
//                             'endYear' => $parsedData['education']['yearsAttended'][$i]['endYear'] ?? null
//                         ];
//                     }
//                 } else if (is_array($parsedData['education'])) {
//                     $education = $parsedData['education'];
//                 }
//             } else if (isset($parsedData['Education'])) {
//                 // If education data uses capitalized keys
//                 foreach ($parsedData['Education'] as $edu) {
//                     $education[] = [
//                         'institution' => $edu['Institution'] ?? '',
//                         'degree' => $edu['Degree'] ?? '',
//                         'fieldOfStudy' => $edu['FieldOfStudy'] ?? '',
//                         'startYear' => $edu['StartYear'] ?? null,
//                         'endYear' => $edu['EndYear'] ?? null,
//                     ];
//                 }
//             }
//             Log::info("Formatted education data: " . json_encode($education));

//             // Update or create the user profile with the extracted data
//             UserProfile::updateOrCreate(
//                 ['user_id' => $this->resumeUpload->user_id],
//                 [
//                     'skills' => $skills,
//                     'experience' => $experience,
//                     'education' => $education,
//                     'parsed_keywords' => implode(', ', ($parsedData['keywords'] ?? [])),
//                     'resume_text' => $this->resumeText,
//                 ]
//             );
//             Log::info("User profile updated successfully for user_id: " . $this->resumeUpload->user_id);
//         } catch (\Exception $e) {
//             Log::error("Failed to update user profile: " . $e->getMessage(), [
//                 'exception' => $e,
//                 'parsedData' => json_encode($parsedData)
//             ]);
//         }
//     }
// }


