# Generated from Selenium IDE
# Test name: t01 - Project object download
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_01_Project_object_download:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_01_Project_object_download(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 1')]")) > 0
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 2')]")) > 0
    self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 1").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"ExternalModules/\"][href*=\"prefix=project_deployment\"]").click()
    self.driver.execute_script("//SAVEDESC:Verify project object.")
    self.driver.execute_script("$.get(window.location.href.replace('performdeployment','projectexport'),function(data,status,xhr){if(data[0].name=='GlobalVariables'&&data[1].name=='MetaDataVersion'&&data[0].items[0].name=='StudyName'&&data[0].items[0].data=='Project Deployment Test 1'&&xhr.getResponseHeader('Content-Type')=='application/json'){$('body').attr('data-exp-ok','1')};$('body').attr('data-exp','1')})")
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "body[data-exp]")))
    assert len(self.driver.find_elements(By.CSS_SELECTOR, "body[data-exp-ok]")) > 0
