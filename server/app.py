# from flask import Flask, request, jsonify
# from flask_pymongo import PyMongo
# from flask_cors import CORS
# import requests

# app = Flask(__name__)
# CORS(app)

# # MongoDB Configuration
# app.config["MONGO_URI"] = "mongodb://localhost:27017/resume_ai"
# mongo = PyMongo(app)

# # Ollama Microservice URL
# OLLAMA_URL = "http://localhost:6000/analyze"

# # Analyze Resume Route
# @app.route('/api/analyze', methods=['POST'])
# def analyze_resume():
#     file = request.files['resume']
#     response = requests.post(OLLAMA_URL, files={'resume': file})
#     return jsonify(response.json())

# # Fetch Jobs Route
# @app.route('/api/jobs', methods=['GET'])
# def fetch_jobs():
#     search_query = request.args.get('search', '')
#     # Simulating API aggregation from LinkedIn, Indeed, etc.
#     jobs = []
#     for portal_url in ['https://api.linkedin.com/jobs', 'https://api.indeed.com/jobs']:
#         resp = requests.get(f"{portal_url}?q={search_query}")
#         if resp.status_code == 200:
#             jobs.extend(resp.json().get('jobs', []))
#     return jsonify(jobs)

# if __name__ == '__main__':
#     app.run(debug=True)

from flask import Flask, jsonify
from flask_pymongo import PyMongo
from flask_cors import CORS

app = Flask(__name__)
CORS(app)

# MongoDB Configuration
app.config["MONGO_URI"] = "mongodb://localhost:27017/ai-resume"
mongo = PyMongo(app)

@app.route('/')
def home():
    return jsonify({"message": "Welcome to the Resume AI Flask Backend!"})

@app.route('/api/testdb', methods=['GET'])
def test_db():
    try:
        test_data = {"msg": "MongoDB Connected via Flask!"}
        result = mongo.db.test.insert_one(test_data)
        return jsonify({
            "status": "Success",
            "inserted_id": str(result.inserted_id),
            "data": test_data
        })
    except Exception as e:
        print("Error inserting to MongoDB:", e)
        return jsonify({"status": "Error", "message": str(e)})

if __name__ == '__main__':
    app.run(port=8000, debug=True)


