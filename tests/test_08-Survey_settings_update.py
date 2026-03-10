# Generated from Selenium IDE
# Test name: t08 - Survey settings update
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_08_Survey_settings_update:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_08_Survey_settings_update(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 1')]")) > 0
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 2')]")) > 0
    if self.driver.execute_script("return (redcap_version.split('.')[0] >= 16 || (redcap_version.split('.')[0] == 15 && redcap_version.split('.')[1] >= 8))"):
      self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 1").click()
      self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"Design/online_designer.php\"]").click()
      self.driver.find_element(By.CSS_SELECTOR, "button[onclick*=\"Surveys/edit_info.php\"]").click()
      WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.NAME, "survey_btn_text_submit")))
      self.driver.find_element(By.NAME, "survey_btn_text_submit").send_keys("Submit Survey")
      self.driver.execute_script("$('#south').remove()")
      self.driver.find_element(By.ID, "surveySettingsSubmit").click()
      WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.ID, "south")))
      self.driver.find_element(By.LINK_TEXT, "My Projects").click()
      self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 2").click()
      self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"ExternalModules/\"][href*=\"prefix=project_deployment\"]").click()
      self.driver.execute_script("if(window.location.hostname=='127.0.0.1'&&$('input[name=\"username\"]').length>0){$('input[name=\"username\"]').val('admin');$('input[name=\"password\"]').val('abc123');$('form[method=\"post\"] input[type=\"submit\"]').trigger('click')}")
      None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"username\"]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
      self.driver.execute_script("//SETDESC:Survey Settings changes identified.")
      self.driver.find_element(By.CSS_SELECTOR, ".changestbl").send_keys("SAVESCREENSHOT")
      self.vars["count"] = self.driver.execute_script("return ''+$('.changestbl tr').length")
      assert(self.vars["count"] == "1")
      assert len(self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"update[surveys]\"]")) > 0
      None if (element := self.driver.find_element(By.CSS_SELECTOR, "input[name=\"update[surveys]\"]")).is_selected() else element.click()
      self.driver.find_element(By.CSS_SELECTOR, "input[type=\"submit\"]").click()
      self.driver.execute_script("//SAVEDESC:No changes identified.")
      self.vars["count"] = self.driver.execute_script("return ''+$('.changestbl tr').length")
      assert(self.vars["count"] == "0")
    else:
      self.driver.execute_script("//SAVEDESC:Not proceeding with this test as REDCap version < 15.8.")
