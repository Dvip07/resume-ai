# from flask import Flask, request, jsonify
# import subprocess
# import json

# app = Flask(__name__)

# @app.route('/llm', methods=['POST'])
# def query_llm():
#     input_text = request.json.get('text', '')
    
    
#     try:
#         result = subprocess.run(
#             ['ollama', 'run', 'llama3.2'],
#             input=input_text,
#             text=True,
#             capture_output=True
#         )
#         response = result.stdout.strip()
#         return jsonify({'response': response})
#     except Exception as e:
#         return jsonify({'error': str(e)}), 500

# if __name__ == '__main__':
#     app.run(port=5001)

from flask import Flask, request, jsonify
import subprocess
import json
import logging

# Set up logging
logging.basicConfig(level=logging.DEBUG)
logger = logging.getLogger(__name__)

app = Flask(__name__)

@app.route('/llm', methods=['POST'])
def query_llm():
    # Log the entire request for debugging
    logger.debug(f"Request JSON: {request.json}")
    
    # Get the prompt and resume_text from the request
    prompt = request.json.get('prompt', '')
    resume_text = request.json.get('resume_text', '')
    
    # Combine them for processing
    input_text = prompt + '\n\nResume Text:\n' + resume_text if resume_text else prompt
    
    logger.info(f"Combined input text: {input_text[:50]}...")  # Log first 100 chars
    
    if not input_text:
        return jsonify({"error": "No input text provided"}), 400
    
    try:
        # Run the ollama command with detailed logging
        logger.info("Starting Ollama subprocess")
        
        # Try using a file to pass the input
        # with open('temp_input.txt', 'w') as f:
        #     f.write(input_text)
        
        # Run ollama command, reading from the file
        cmd = ['ollama', 'run', 'llama3.2']
        logger.info(f"Running command: {' '.join(cmd)}")
        
        # First, try with file input
        result = subprocess.run(
            cmd,
            input=input_text,
            text=True,
            capture_output=True,
            timeout=120  # Add a timeout
        )
        
        # Log stdout and stderr
        logger.info(f"Command stdout: {result.stdout}")
        logger.info(f"Command stderr: {result.stderr}")
        
        # Capture the response
        response = result.stdout.strip()
        
        # Return a simple response for now
        if response:
            return jsonify({"response": response})
        else:
            return jsonify({"response": "No output from Ollama", "stderr": result.stderr}), 500
            
    except subprocess.TimeoutExpired:
        logger.error("Ollama command timed out")
        return jsonify({"error": "Command timed out"}), 504
    except Exception as e:
        logger.exception("Exception in LLM query")
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    app.run(port=5001, debug=True)